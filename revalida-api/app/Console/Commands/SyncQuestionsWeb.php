<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SyncQuestionsWeb extends Command
{
    protected $signature = 'questions:sync-web';

    protected $description = 'Executa o pipeline completo de ingestão web, priorizando extração oficial e usando Gemini apenas como fallback';

    public function handle(): int
    {
        $script = base_path('tools/run_questions_pipeline.sh');
        $environment = $_ENV;

        if (!(bool) config('features.ai_enabled', true)) {
            $environment['AI_ENABLED'] = 'false';
            $this->warn('AI_ENABLED=false no .env. O pipeline vai pular apenas o fallback via IA e tentará importar a prova oficial quando disponível.');
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
