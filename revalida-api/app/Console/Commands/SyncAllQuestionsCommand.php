<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncAllQuestionsCommand extends Command
{
    protected $signature = 'questions:sync-all
        {--exam=Revalida : Escopo do exame para a sincronização do Revalida}
        {--phase=first : Fase do Revalida (first, second ou all)}
        {--limit-sources=0 : Limita a quantidade de fontes no crawl do Revalida}
        {--generate-limit=0 : Limita a quantidade de prompts processados na IA}
        {--skip-ai : Pula apenas o fallback via IA no Revalida}
        {--skip-revalida : Não executa a sincronização do Revalida}
        {--skip-estudo-geral : Não executa a cópia de estudo geral}
        {--estudo-geral-db=revalida_pre_reset_check : Banco PostgreSQL de origem para estudo geral}
        {--estudo-geral-limit=0 : Limite opcional para a cópia de estudo geral}';

    protected $description = 'Sincroniza Revalida e estudo geral em um único comando';

    public function handle(): int
    {
        if (!(bool) $this->option('skip-revalida')) {
            $this->components->info('Sincronizando trilha Revalida...');

            $revalidaOptions = [
                '--exam' => (string) $this->option('exam'),
                '--phase' => (string) $this->option('phase'),
            ];

            $limitSources = (int) $this->option('limit-sources');
            if ($limitSources > 0) {
                $revalidaOptions['--limit-sources'] = $limitSources;
            }

            $generateLimit = (int) $this->option('generate-limit');
            if ($generateLimit > 0) {
                $revalidaOptions['--generate-limit'] = $generateLimit;
            }

            if ((bool) $this->option('skip-ai')) {
                $revalidaOptions['--skip-ai'] = true;
            }

            $exitCode = $this->call('revalida:sync', $revalidaOptions);
            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        if ((bool) $this->option('skip-estudo-geral')) {
            $this->components->info('Cópia de estudo geral pulada por opção.');
            return self::SUCCESS;
        }

        $studyGeneralDb = trim((string) $this->option('estudo-geral-db'));
        if ($studyGeneralDb === '') {
            $this->error('Informe um banco de origem em --estudo-geral-db.');
            return self::FAILURE;
        }

        if (!$this->databaseExists($studyGeneralDb)) {
            $this->error("Banco de estudo geral não encontrado: {$studyGeneralDb}");
            $this->line('Dica: restaure o backup grande primeiro ou passe outro nome em --estudo-geral-db.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info("Copiando estudo geral de {$studyGeneralDb}...");

        $copyOptions = [
            'database' => $studyGeneralDb,
            '--category' => 'estudo_geral',
        ];

        $studyGeneralLimit = (int) $this->option('estudo-geral-limit');
        if ($studyGeneralLimit > 0) {
            $copyOptions['--limit'] = $studyGeneralLimit;
        }

        return $this->call('questions:copy-database', $copyOptions);
    }

    private function databaseExists(string $database): bool
    {
        $driver = DB::getDriverName();

        if ($driver !== 'pgsql') {
            return true;
        }

        $rows = DB::select('SELECT 1 FROM pg_database WHERE datname = ? LIMIT 1', [$database]);

        return $rows !== [];
    }
}
