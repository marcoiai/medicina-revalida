<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RevalidaSyncCommand extends Command
{
    protected $signature = 'revalida:sync
        {--exam=Revalida : Escopo do exame para o crawl}
        {--phase=first : Fase do Revalida (first, second ou all)}
        {--limit-sources=0 : Limita a quantidade de fontes no crawl}
        {--generate-limit=0 : Limita a quantidade de prompts processados na IA}
        {--skip-ai : Pula apenas o fallback via IA; se houver objetiva oficial extraída, ela ainda será importada}';

    protected $description = 'Executa o pipeline completo do Revalida: crawl, download, parsing, extração oficial e fallback via IA quando necessário';

    public function handle(): int
    {
        $script = base_path('tools/run_web_pipeline.py');
        $pythonBinary = $this->resolvePythonBinary();
        $aiEnabled = (bool) config('features.ai_enabled', true);

        if (!file_exists($script)) {
            $this->error("Script não encontrado: {$script}");
            return self::FAILURE;
        }

        $command = [
            $pythonBinary,
            $script,
            '--full',
            '--import-json',
            '--exam',
            (string) $this->option('exam'),
            '--phase',
            (string) $this->option('phase'),
        ];

        $limitSources = (int) $this->option('limit-sources');
        if ($limitSources > 0) {
            $command[] = '--limit-sources';
            $command[] = (string) $limitSources;
        }

        $generateLimit = (int) $this->option('generate-limit');
        if ($generateLimit > 0) {
            $command[] = '--generate-limit';
            $command[] = (string) $generateLimit;
        }

        $skipAi = (bool) $this->option('skip-ai');
        if (!$aiEnabled) {
            $skipAi = true;
            $this->warn('AI_ENABLED=false no .env. O pipeline vai pular apenas o fallback via IA e tentará importar a prova oficial quando disponível.');
        }

        if ($skipAi) {
            $command[] = '--skip-ai';
        }

        $process = new Process($command, base_path(), $_ENV);
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->error('Pipeline do Revalida finalizado com erro.');
            return self::FAILURE;
        }

        $this->info('Pipeline do Revalida finalizado com sucesso.');
        return self::SUCCESS;
    }

    private function resolvePythonBinary(): string
    {
        $candidates = [
            base_path('.venv/bin/python3'),
            base_path('.venv/bin/python'),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'python3';
    }
}
