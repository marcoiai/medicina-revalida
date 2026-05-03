<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class QuestionsSyncEverythingCommand extends Command
{
    protected $signature = 'questions:sync-everything
        {--study-general-db=revalida_pre_reset_check : Banco PostgreSQL de origem para estudo geral}
        {--study-general-limit=0 : Limite opcional para a cópia de estudo geral}
        {--limit-sources=0 : Limita a quantidade de fontes no crawl amplo}
        {--generate-limit=0 : Limita a quantidade de prompts processados na IA}
        {--skip-ai : Pula apenas o fallback via IA}
        {--skip-revalida : Não executa a sincronização ampla do Revalida}
        {--skip-study-general : Não executa a cópia de estudo geral}
        {--only-study-general : Executa somente a cópia de estudo geral, sem Revalida}';

    protected $description = 'Executa o fluxo completo: Revalida amplo + cópia de estudo geral';

    public function handle(): int
    {
        $syncOptions = [
            '--exam' => 'all',
            '--phase' => 'all',
        ];

        $onlyStudyGeneral = (bool) $this->option('only-study-general');
        if ($onlyStudyGeneral) {
            $syncOptions['--skip-revalida'] = true;
        }

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

        if ((bool) $this->option('skip-revalida')) {
            $syncOptions['--skip-revalida'] = true;
        }

        if ((bool) $this->option('skip-study-general')) {
            $syncOptions['--skip-estudo-geral'] = true;
        } else {
            $syncOptions['--estudo-geral-db'] = (string) $this->option('study-general-db');

            $studyGeneralLimit = (int) $this->option('study-general-limit');
            if ($studyGeneralLimit > 0) {
                $syncOptions['--estudo-geral-limit'] = $studyGeneralLimit;
            }
        }

        $this->components->info('Executando fluxo completo de questões...');

        $exitCode = $this->call('questions:sync-all', $syncOptions);
        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $this->info('Fluxo completo finalizado com sucesso.');
        return self::SUCCESS;
    }
}
