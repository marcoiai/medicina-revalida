<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SyncQuestionsWeb extends Command
{
    protected $signature = 'questions:sync-web
        {--exam=Revalida : Escopo do crawl. Use all para incluir todas as fontes do tools/sources.json}
        {--phase=first : Fase do Revalida. Use all para incluir primeira e segunda fase}
        {--category=revalida : Categoria final de importação. Use estudo_geral para crawler amplo de estudo geral}
        {--sources-file= : Arquivo JSON alternativo com fontes oficiais a serem rastreadas}
        {--ai-provider= : Provedor de IA para fallback e revisão (openai ou gemini)}
        {--limit-sources=0 : Limita a quantidade de fontes processadas}
        {--generate-limit=0 : Limita a quantidade de prompts processados na IA}
        {--skip-ai : Pula apenas o fallback via IA; se houver prova oficial extraída, ela ainda será importada}';

    protected $description = 'Executa o pipeline completo de ingestão web, priorizando extração oficial e usando IA apenas como fallback';

    public function handle(): int
    {
        $script = base_path('tools/run_questions_pipeline.sh');
        $environment = $_ENV;
        $exam = (string) $this->option('exam');
        $phase = (string) $this->option('phase');
        $category = strtolower(trim((string) $this->option('category')));
        $limitSources = max(0, (int) $this->option('limit-sources'));
        $generateLimit = max(0, (int) $this->option('generate-limit'));
        $skipAi = (bool) $this->option('skip-ai');

        if (!(bool) config('features.ai_enabled', true)) {
            $environment['AI_ENABLED'] = 'false';
            $this->warn('AI_ENABLED=false no .env. O pipeline vai pular apenas o fallback via IA e tentará importar a prova oficial quando disponível.');
        }

        $environment['QUESTIONS_EXAM_SCOPE'] = $exam;
        $environment['QUESTIONS_PHASE_SCOPE'] = $phase;
        $environment['QUESTIONS_IMPORT_CATEGORY'] = in_array($category, ['estudo_geral', 'estudogeral', 'geral', 'general'], true)
            ? 'estudo_geral'
            : 'revalida';
        $aiProvider = strtolower(trim((string) $this->option('ai-provider')));
        if ($aiProvider !== '') {
            $environment['QUESTION_AI_PROVIDER'] = $aiProvider;
        }
        $sourcesFile = trim((string) $this->option('sources-file'));
        if ($sourcesFile !== '') {
            $environment['QUESTIONS_SOURCES_FILE'] = $sourcesFile;
        }

        if ($environment['QUESTIONS_IMPORT_CATEGORY'] === 'estudo_geral') {
            $environment['QUESTIONS_EXAM_SCOPE'] = 'all';
            $environment['QUESTIONS_PHASE_SCOPE'] = 'all';
        }

        if ($limitSources > 0) {
            $environment['QUESTIONS_LIMIT_SOURCES'] = (string) $limitSources;
        }
        if ($generateLimit > 0) {
            $environment['QUESTIONS_GENERATE_LIMIT'] = (string) $generateLimit;
        }
        if ($skipAi) {
            $environment['AI_ENABLED'] = 'false';
        }

        if (!file_exists($script)) {
            $this->error("Script não encontrado: {$script}");
            return self::FAILURE;
        }

        $process = Process::fromShellCommandline('bash ' . escapeshellarg($script), base_path(), $environment);
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->error('Pipeline finalizado com erro.');
            return self::FAILURE;
        }

        $this->info('Pipeline finalizado com sucesso.');
        return self::SUCCESS;
    }
}
