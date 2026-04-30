<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SyncQuestionsWeb extends Command
{
    protected $signature = 'questions:sync-web';

    protected $description = 'Executa o pipeline completo de ingestão web, geração Gemini e importação no banco';

    public function handle(): int
    {
        $script = base_path('tools/run_questions_pipeline.sh');

        if (!file_exists($script)) {
            $this->error("Script não encontrado: {$script}");
            return self::FAILURE;
        }

        $process = Process::fromShellCommandline('bash ' . escapeshellarg($script), base_path());
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
