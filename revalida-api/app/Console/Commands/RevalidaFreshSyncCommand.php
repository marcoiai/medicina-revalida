<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class RevalidaFreshSyncCommand extends Command
{
    protected $signature = 'revalida:fresh-sync
        {--skip-sync : Apenas limpa banco e artefatos, sem executar o pipeline depois}
        {--skip-ai : Limpa tudo e pula apenas o fallback via IA; se houver objetiva oficial extraída, ela ainda será importada}
        {--exam=Revalida : Escopo do exame para a nova sincronização}
        {--phase=first : Fase do Revalida (first, second ou all)}
        {--limit-sources=0 : Limita a quantidade de fontes no crawl}
        {--generate-limit=0 : Limita a quantidade de prompts processados na IA}';

    protected $description = 'Regera o fluxo do Revalida do zero: limpa banco de questões, fontes e artefatos do pipeline, depois roda nova sincronização';

    public function handle(): int
    {
        $this->components->info('Limpando banco de questões e artefatos do pipeline...');

        $this->truncateQuestionData();
        $this->cleanImportArtifacts();

        if ((bool) $this->option('skip-sync')) {
            $this->components->info('Limpeza concluída. Nenhuma sincronização foi executada.');
            return self::SUCCESS;
        }

        $syncOptions = [
            '--exam' => (string) $this->option('exam'),
            '--phase' => (string) $this->option('phase'),
        ];

        $limitSources = (int) $this->option('limit-sources');
        if ($limitSources > 0) {
            $syncOptions['--limit-sources'] = $limitSources;
        }

        $generateLimit = (int) $this->option('generate-limit');
        if ($generateLimit > 0) {
            $syncOptions['--generate-limit'] = $generateLimit;
        }

        if ((bool) $this->option('skip-ai')) {
            $syncOptions['--skip-ai'] = true;
        }

        $this->newLine();
        $this->components->info('Iniciando nova sincronização do zero...');

        return $this->call('revalida:sync', $syncOptions);
    }

    private function truncateQuestionData(): void
    {
        $tables = array_values(array_filter([
            Schema::hasTable('questions') ? 'questions' : null,
            Schema::hasTable('question_sources') ? 'question_sources' : null,
        ]));

        if ($tables === []) {
            $this->line('- Banco: tabelas de questões não encontradas, nada para limpar.');
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $quotedTables = implode(', ', array_map(
                static fn (string $table): string => sprintf('"%s"', $table),
                $tables
            ));

            DB::statement("TRUNCATE TABLE {$quotedTables} RESTART IDENTITY CASCADE");
        } else {
            Schema::disableForeignKeyConstraints();
            try {
                foreach ($tables as $table) {
                    DB::table($table)->truncate();
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }
        }

        $this->line('- Banco: questões e fontes limpas com reset de IDs.');
    }

    private function cleanImportArtifacts(): void
    {
        $paths = [
            storage_path('imports/pdfs'),
            storage_path('imports/text'),
            storage_path('imports/json/ai_batches'),
            storage_path('imports/generated'),
            storage_path('imports/validation'),
            storage_path('imports/pdf_links.json'),
            storage_path('imports/pdf_manifest.json'),
            storage_path('imports/text_manifest.json'),
            storage_path('imports/questions.json'),
            storage_path('imports/questions.rejected.json'),
        ];

        $removed = 0;

        foreach ($paths as $path) {
            if (is_dir($path)) {
                if (File::deleteDirectory($path)) {
                    $removed++;
                }
                continue;
            }

            if (is_file($path)) {
                File::delete($path);
                $removed++;
            }
        }

        $this->line("- Artefatos: {$removed} caminho(s) limpos em storage/imports.");
    }
}
