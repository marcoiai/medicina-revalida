<?php

namespace App\Console\Commands;

use App\Models\Question;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class QuestionsScaleTo100kCommand extends Command
{
    protected $signature = 'questions:scale-to-100k
        {--progress : Exibe progresso por etapas no fluxo}
        {--skip-discovery : Pula a descoberta de novas fontes oficiais}
        {--sources-file= : Usa um arquivo de fontes já mesclado}
        {--discover-out=storage/imports/official_sources.discovered.json : Saída do descobridor de fontes}
        {--merged-sources=storage/imports/official_sources.merged.json : Arquivo temporário com fontes oficiais mescladas}
        {--discover-max-depth=2 : Profundidade máxima da descoberta de fontes}
        {--discover-max-pages=80 : Máximo de páginas por seed na descoberta}
        {--crawl-limit-sources=0 : Limita fontes no crawl amplo}
        {--quiet-discovery : Reduz a verbosidade da descoberta de fontes}
        {--study-general-db=revalida_pre_reset_check : Banco de origem para cópia de estudo geral}
        {--study-general-limit=0 : Limite da cópia de estudo geral}
        {--skip-revalida-web : Pula o crawl amplo de Revalida}
        {--skip-study-general-web : Pula o crawl web de estudo geral}
        {--skip-study-general-copy : Pula a cópia de estudo geral do banco}
        {--authorial-amount=0 : Quantidade de questões autorais estilo Revalida a gerar}
        {--authorial-batch-size=200 : Tamanho do lote por prompt da geração autoral}
        {--authorial-area=all : Área para a geração autoral}
        {--authorial-run-id= : Run id manual para a geração autoral}
        {--ai-provider= : Provedor de IA para fallback e geração autoral (openai ou gemini)}
        {--with-ai : Executa a geração autoral com IA}
        {--skip-ai : Pula IA nos fluxos web e autorais}';

    protected $description = 'Orquestra descoberta de fontes, crawl web e geração para escalar o banco de questões';

    public function handle(): int
    {
        $aiEnabled = (bool) config('features.ai_enabled', true);
        $aiProvider = strtolower(trim((string) $this->option('ai-provider')));
        $baseSources = base_path('tools/sources.json');
        $discoveredOut = base_path((string) $this->option('discover-out'));
        $mergedSources = base_path((string) $this->option('merged-sources'));
        $sourcesFile = trim((string) ($this->option('sources-file') ?? ''));

        $stepIndex = 0;
        $stepTotal = $this->countEnabledSteps();
        $showProgress = (bool) $this->option('progress');
        $nextStep = function (string $label) use (&$stepIndex, $stepTotal, $showProgress): void {
            $stepIndex++;
            if ($showProgress) {
                $this->line(sprintf('[%d/%d] %s', $stepIndex, $stepTotal, $label));
            } else {
                $this->newLine();
                $this->components->info($label);
            }
        };

        if (!$this->option('skip-discovery')) {
            $nextStep('Descobrindo novas fontes oficiais...');
            $discoveryResult = $this->runProcess([
                $this->resolvePythonBinary(),
                base_path('tools/discover_official_sources.py'),
                '--out',
                $discoveredOut,
                '--max-depth',
                (string) max(0, (int) $this->option('discover-max-depth')),
                '--max-pages',
                (string) max(1, (int) $this->option('discover-max-pages')),
                ...( (bool) $this->option('quiet-discovery') ? ['--quiet'] : [] ),
            ], 'Descoberta de fontes em andamento');
            if ($discoveryResult !== self::SUCCESS) {
                return $discoveryResult;
            }
            $this->mergeSourcesFile($baseSources, $discoveredOut, $mergedSources);
            $sourcesFile = $mergedSources;
        } elseif ($sourcesFile === '') {
            $sourcesFile = $mergedSources;
        }

        if ($sourcesFile === '' && is_file($mergedSources)) {
            $sourcesFile = $mergedSources;
        }

        if ($sourcesFile === '') {
            $sourcesFile = $baseSources;
        }

        if (!(bool) $this->option('skip-revalida-web')) {
            $nextStep('Rodando crawl web amplo de Revalida...');
            $revalidaExit = $this->call('questions:sync-web', [
                '--exam' => 'all',
                '--phase' => 'all',
                '--category' => 'revalida',
                '--sources-file' => $sourcesFile,
                '--limit-sources' => max(0, (int) $this->option('crawl-limit-sources')),
                '--skip-ai' => (bool) $this->option('skip-ai') || !$aiEnabled,
                ...( $aiProvider !== '' ? ['--ai-provider' => $aiProvider] : [] ),
            ]);

            if ($revalidaExit !== self::SUCCESS) {
                return $revalidaExit;
            }
        }

        if (!(bool) $this->option('skip-study-general-web')) {
            $nextStep('Rodando crawl web de estudo geral...');
            $studyGeneralExit = $this->call('questions:sync-study-general-web', [
                '--sources-file' => $sourcesFile,
                '--limit-sources' => max(0, (int) $this->option('crawl-limit-sources')),
                '--skip-ai' => (bool) $this->option('skip-ai') || !$aiEnabled,
                ...( $aiProvider !== '' ? ['--ai-provider' => $aiProvider] : [] ),
            ]);

            if ($studyGeneralExit !== self::SUCCESS) {
                return $studyGeneralExit;
            }
        }

        if (!(bool) $this->option('skip-study-general-copy')) {
            $nextStep('Copiando estudo geral do banco base...');
            $copyExit = $this->call('questions:copy-database', [
                'database' => (string) $this->option('study-general-db'),
                '--category' => 'estudo_geral',
                '--limit' => max(0, (int) $this->option('study-general-limit')),
            ]);

            if ($copyExit !== self::SUCCESS) {
                return $copyExit;
            }
        }

        $authorialAmount = max(0, (int) $this->option('authorial-amount'));
        if ($authorialAmount > 0) {
            $nextStep("Gerando {$authorialAmount} questões autorais estilo Revalida...");

            $runId = trim((string) $this->option('authorial-run-id'));
            if ($runId === '') {
                $runId = 'scale_to_100k_' . now()->format('Ymd_His');
            }

            $generateOptions = [
                '--amount' => $authorialAmount,
                '--area' => (string) $this->option('authorial-area'),
                '--batch-size' => max(1, (int) $this->option('authorial-batch-size')),
                '--run-id' => Str::slug($runId, '_'),
            ];

            if ($aiProvider !== '') {
                $generateOptions['--ai-provider'] = $aiProvider;
            }

            $runWithAi = (bool) $this->option('with-ai') && $aiEnabled && !(bool) $this->option('skip-ai');
            if ($runWithAi) {
                $generateOptions['--with-ai'] = true;
            }

            $generateExit = $this->call('revalida:generate', $generateOptions);
            if ($generateExit !== self::SUCCESS) {
                return $generateExit;
            }

            if ($runWithAi) {
                $nextStep("Revisando run {$runId}...");
                $reviewExit = $this->call('revalida:review', [
                    '--run' => Str::slug($runId, '_'),
                ]);
                if ($reviewExit !== self::SUCCESS) {
                    return $reviewExit;
                }

                $nextStep("Importando run {$runId}...");
                $importExit = $this->call('revalida:import', [
                    '--run' => Str::slug($runId, '_'),
                    '--approved' => true,
                ]);
                if ($importExit !== self::SUCCESS) {
                    return $importExit;
                }
            } else {
                $this->warn('Geração autoral feita sem IA. Os prompts foram criados, mas não houve importação automática.');
            }
        }

        if ($showProgress) {
            $this->line(sprintf('[%d/%d] Concluído', max($stepIndex, 1), max($stepTotal, 1)));
        } else {
            $this->newLine();
        }
        $this->info('Fluxo de escala concluído com sucesso.');
        $this->line('Fontes oficiais mescladas: ' . $this->toRelativePath($sourcesFile));

        $totalRevalida = Question::query()->where(function ($query) {
            $query->where('study_category', 'revalida')
                ->orWhereNull('study_category')
                ->orWhereRaw("TRIM(COALESCE(study_category, '')) = ''");
        })->count();
        $totalGeral = Question::query()->where('study_category', 'estudo_geral')->count();
        $total = Question::query()->count();

        $this->line("Totais atuais -> Revalida: {$totalRevalida} | Estudo geral: {$totalGeral} | Total: {$total}");

        return self::SUCCESS;
    }

    private function countEnabledSteps(): int
    {
        $steps = 0;
        if (!(bool) $this->option('skip-discovery')) {
            $steps++;
        }
        if (!(bool) $this->option('skip-revalida-web')) {
            $steps++;
        }
        if (!(bool) $this->option('skip-study-general-web')) {
            $steps++;
        }
        if (!(bool) $this->option('skip-study-general-copy')) {
            $steps++;
        }
        if (max(0, (int) $this->option('authorial-amount')) > 0) {
            $steps++;
            if ((bool) $this->option('with-ai') && (bool) config('features.ai_enabled', true) && !(bool) $this->option('skip-ai')) {
                $steps += 2; // review + import
            }
        }

        return max(1, $steps);
    }

    private function runProcess(array $command, ?string $heartbeatLabel = null): int
    {
        $process = new Process($command, base_path(), $_ENV);
        $process->setTimeout(null);

        if ($heartbeatLabel === null) {
            $process->run(function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });
        } else {
            $process->start();
            $lastHeartbeat = microtime(true);
            while ($process->isRunning()) {
                $this->output->write($process->getIncrementalOutput());
                $this->output->write($process->getIncrementalErrorOutput());

                if (microtime(true) - $lastHeartbeat >= 15) {
                    $this->line($heartbeatLabel . '...');
                    $lastHeartbeat = microtime(true);
                }

                usleep(250000);
            }

            $this->output->write($process->getIncrementalOutput());
            $this->output->write($process->getIncrementalErrorOutput());
        }

        if (!$process->isSuccessful()) {
            $this->error('Etapa externa finalizada com erro.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function mergeSourcesFile(string $baseSources, string $discoveredOut, string $mergedOut): void
    {
        $base = $this->readJsonList($baseSources);
        $discovered = $this->readJsonList($discoveredOut);
        $seen = [];
        $merged = [];

        foreach (array_merge($base, $discovered) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $key = Str::lower($url);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $item;
        }

        file_put_contents(
            $mergedOut,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function readJsonList(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
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

    private function toRelativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $path;
    }
}
