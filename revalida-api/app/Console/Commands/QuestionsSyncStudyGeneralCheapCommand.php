<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class QuestionsSyncStudyGeneralCheapCommand extends Command
{
    protected $signature = 'questions:sync-study-general-cheap
        {--limit-sources=10 : Limita a quantidade de fontes no crawl}
        {--skip-ai : Pula o fallback via IA}
        {--ai-provider=openai : Provedor de IA a usar (openai ou gemini)}';

    protected $description = 'Executa o estudo geral em modo econômico, com descoberta leve e OpenAI por padrão';

    public function handle(): int
    {
        $options = [
            '--skip-discovery' => true,
            '--discover-max-depth' => 1,
            '--discover-max-pages' => 20,
            '--search-timeout' => 5,
            '--limit-sources' => max(1, (int) $this->option('limit-sources')),
            '--ai-provider' => (string) $this->option('ai-provider'),
        ];

        if ((bool) $this->option('skip-ai')) {
            $options['--skip-ai'] = true;
        }

        $this->components->info('Executando estudo geral em modo econômico...');

        $exitCode = $this->call('questions:sync-study-general-web', $options);
        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $this->info('Modo econômico de estudo geral finalizado com sucesso.');
        return self::SUCCESS;
    }
}
