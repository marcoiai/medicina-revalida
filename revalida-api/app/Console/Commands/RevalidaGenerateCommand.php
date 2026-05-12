<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class RevalidaGenerateCommand extends Command
{
    protected $signature = 'revalida:generate
        {--amount= : Quantidade total de questões a planejar}
        {--area=all : Área específica (clinica, go, pediatria, cirurgia, preventiva) ou all}
        {--batch-size= : Quantidade por lote de prompt}
        {--output-dir=storage/imports/generated : Diretório base para runs}
        {--run-id= : Identificador manual do run}
        {--seed= : Seed numérica para distribuição determinística}
        {--with-ai : Executa o gerador Gemini após criar os prompts}';

    protected $description = 'Planeja e cria lotes de geração de questões estilo Revalida 1ª fase';

    public function handle(): int
    {
        $config = config('revalida_generation');
        $aiEnabled = (bool) config('features.ai_enabled', true);
        if (!is_array($config) || !isset($config['areas'], $config['style_mix'], $config['style_angles'], $config['difficulty_mix'])) {
            $this->error('Configuração revalida_generation inválida ou ausente.');
            return self::FAILURE;
        }

        $amount = max(1, (int) ($this->option('amount') ?: ($config['default_amount'] ?? 200)));
        $batchSize = max(1, (int) ($this->option('batch-size') ?: ($config['default_batch_size'] ?? 50)));
        $seed = $this->resolveSeed();
        $areaFilter = (string) ($this->option('area') ?? 'all');
        $selectedAreas = $this->resolveAreaKeys($areaFilter, (array) $config['areas']);

        if ($selectedAreas === []) {
            $this->error("Área inválida: {$areaFilter}");
            return self::FAILURE;
        }

        $runId = $this->resolveRunId();
        $runDir = $this->prepareRunDirectory((string) $this->option('output-dir'), $runId);
        $promptsDir = $runDir . DIRECTORY_SEPARATOR . 'prompts';

        if (!is_dir($promptsDir) && !mkdir($promptsDir, 0777, true) && !is_dir($promptsDir)) {
            $this->error("Não foi possível criar o diretório de prompts: {$promptsDir}");
            return self::FAILURE;
        }

        $distribution = $this->buildAreaDistribution($amount, $selectedAreas, (array) $config['areas']);
        $plan = $this->buildPlan(
            runId: $runId,
            seed: $seed,
            amount: $amount,
            batchSize: $batchSize,
            distribution: $distribution,
            config: $config,
        );

        foreach ($plan['batches'] as &$batch) {
            $promptPath = $promptsDir . DIRECTORY_SEPARATOR . $batch['file_name'];
            file_put_contents($promptPath, $this->buildPrompt($batch, $config));
            $batch['prompt_path'] = $this->toRelativeBasePath($promptPath);
        }
        unset($batch);

        $planPath = $runDir . DIRECTORY_SEPARATOR . 'generation_plan.json';
        file_put_contents(
            $planPath,
            json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->info("Run criado: {$runId}");
        $this->line('Plano: ' . $this->toRelativeBasePath($planPath));
        $this->line('Prompts: ' . $this->toRelativeBasePath($promptsDir));
        $this->line('Distribuição:');
        foreach ($distribution as $areaKey => $count) {
            $label = (string) $config['areas'][$areaKey]['label'];
            $this->line("- {$label}: {$count}");
        }

        $withAi = (bool) $this->option('with-ai');
        if ($withAi && !$aiEnabled) {
            $this->warn('AI_ENABLED=false no .env. Vou gerar apenas o plano e os prompts, sem chamar a IA.');
            $withAi = false;
        }

        if ($withAi) {
            $generatedPath = $runDir . DIRECTORY_SEPARATOR . 'questions.generated.json';
            $rejectedPath = $runDir . DIRECTORY_SEPARATOR . 'questions.rejected.json';

            $result = $this->runAiGeneration($promptsDir, $generatedPath, $rejectedPath);
            if ($result !== self::SUCCESS) {
                return $result;
            }

            $this->line('Saída gerada: ' . $this->toRelativeBasePath($generatedPath));
            $this->line('Rejeitados: ' . $this->toRelativeBasePath($rejectedPath));
            $this->line("Próximo passo: php artisan revalida:review --run={$runId}");
            return self::SUCCESS;
        }

        $this->line("Próximo passo: php artisan revalida:review --run={$runId}");
        if ($aiEnabled) {
            $this->line("Se quiser gerar via Gemini no mesmo fluxo: php artisan revalida:generate --run-id={$runId} --amount={$amount} --area={$areaFilter} --batch-size={$batchSize} --with-ai");
        } else {
            $this->line('IA globalmente desligada via AI_ENABLED=false no .env.');
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, array<string, mixed>> $areas
     * @return string[]
     */
    private function resolveAreaKeys(string $areaFilter, array $areas): array
    {
        $normalized = $this->normalizeText($areaFilter);
        if ($normalized === '' || $normalized === 'all') {
            return array_keys($areas);
        }

        foreach ($areas as $key => $area) {
            $aliases = array_map([$this, 'normalizeText'], $area['aliases'] ?? []);
            $aliases[] = $this->normalizeText((string) $key);
            $aliases[] = $this->normalizeText((string) ($area['label'] ?? ''));
            $aliases = array_values(array_filter(array_unique($aliases)));

            if (in_array($normalized, $aliases, true)) {
                return [$key];
            }
        }

        return [];
    }

    private function resolveSeed(): int
    {
        $seedOption = $this->option('seed');
        if ($seedOption !== null && $seedOption !== '') {
            return (int) $seedOption;
        }

        return random_int(10_000, 999_999);
    }

    private function resolveRunId(): string
    {
        $runId = trim((string) ($this->option('run-id') ?? ''));
        if ($runId !== '') {
            return Str::slug($runId, '_');
        }

        return 'run_' . now()->format('Ymd_His');
    }

    private function prepareRunDirectory(string $baseDir, string $runId): string
    {
        $root = base_path(trim($baseDir, '/'));
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }

        $runDir = $root . DIRECTORY_SEPARATOR . $runId;
        if (!is_dir($runDir)) {
            mkdir($runDir, 0777, true);
        }

        return $runDir;
    }

    /**
     * @param string[] $selectedAreas
     * @param array<string, array<string, mixed>> $areas
     * @return array<string, int>
     */
    private function buildAreaDistribution(int $amount, array $selectedAreas, array $areas): array
    {
        if (count($selectedAreas) === 1) {
            return [$selectedAreas[0] => $amount];
        }

        $weights = [];
        foreach ($selectedAreas as $key) {
            $weights[$key] = (float) ($areas[$key]['weight'] ?? 0);
        }

        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            $equal = intdiv($amount, count($selectedAreas));
            $distribution = array_fill_keys($selectedAreas, $equal);
            $remainder = $amount - array_sum($distribution);
            for ($i = 0; $i < $remainder; $i++) {
                $distribution[$selectedAreas[$i]]++;
            }

            return $distribution;
        }

        $base = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $key => $weight) {
            $exact = $amount * ($weight / $totalWeight);
            $base[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $base[$key];
            $allocated += $base[$key];
        }

        $remaining = $amount - $allocated;
        arsort($remainders);
        foreach (array_keys($remainders) as $key) {
            if ($remaining <= 0) {
                break;
            }
            $base[$key]++;
            $remaining--;
        }

        return $base;
    }

    /**
     * @param array<string, int> $distribution
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function buildPlan(
        string $runId,
        int $seed,
        int $amount,
        int $batchSize,
        array $distribution,
        array $config,
    ): array {
        $batches = [];
        $batchCursor = 1;

        foreach ($distribution as $areaKey => $count) {
            $areaConfig = $config['areas'][$areaKey];
            $topics = $this->seededShuffle((array) $areaConfig['topics'], "{$seed}|{$areaKey}|topics");
            $styles = $this->buildStyleSequence($count, (array) $config['style_mix'], "{$seed}|{$areaKey}|styles");
            $difficulties = $this->buildDifficultySequence($count, (array) $config['difficulty_mix'], "{$seed}|{$areaKey}|difficulties");

            $questions = [];
            for ($index = 0; $index < $count; $index++) {
                $style = $styles[$index];
                $angles = (array) ($config['style_angles'][$style] ?? []);
                $angle = $angles[$index % max(1, count($angles))] ?? 'conduta baseada no SUS';
                $topic = $topics[$index % max(1, count($topics))] ?? 'Tema geral do Revalida';
                $difficulty = $difficulties[$index] ?? 'Média';

                $questions[] = [
                    'slot' => $index + 1,
                    'tema_base' => $topic,
                    'estilo' => $style,
                    'angulo' => $angle,
                    'dificuldade_alvo' => $difficulty,
                ];
            }

            foreach (array_chunk($questions, $batchSize) as $chunkIndex => $chunk) {
                $batchId = sprintf('batch_%03d', $batchCursor);
                $batches[] = [
                    'batch_id' => $batchId,
                    'file_name' => "{$batchId}_{$areaKey}.prompt.txt",
                    'run_id' => $runId,
                    'area_key' => $areaKey,
                    'area_label' => (string) $areaConfig['label'],
                    'question_target' => count($chunk),
                    'style_distribution' => $this->countStyles($chunk),
                    'difficulty_distribution' => $this->countDifficulties($chunk),
                    'topics' => $chunk,
                    'reference' => "revalida_seed_bank | {$runId} | {$batchId}",
                    'tags' => ['revalida', 'primeira_fase', $areaKey, $batchId],
                    'chunk_index' => $chunkIndex + 1,
                ];
                $batchCursor++;
            }
        }

        return [
            'run_id' => $runId,
            'created_at' => now()->toIso8601String(),
            'seed' => $seed,
            'amount' => $amount,
            'batch_size' => $batchSize,
            'exam' => (string) ($config['exam'] ?? 'Revalida'),
            'phase' => (string) ($config['phase'] ?? 'first'),
            'distribution' => $distribution,
            'batches' => $batches,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     * @return array<string, int>
     */
    private function countStyles(array $chunk): array
    {
        $counts = [];
        foreach ($chunk as $item) {
            $style = (string) ($item['estilo'] ?? 'desconhecido');
            $counts[$style] = ($counts[$style] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     * @return array<string, int>
     */
    private function countDifficulties(array $chunk): array
    {
        $counts = [];
        foreach ($chunk as $item) {
            $difficulty = (string) ($item['dificuldade_alvo'] ?? 'Média');
            $counts[$difficulty] = ($counts[$difficulty] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<int, string> $items
     * @return array<int, string>
     */
    private function seededShuffle(array $items, string $seedKey): array
    {
        return Collection::make($items)
            ->map(fn (string $item, int $index): array => [
                'value' => $item,
                'sort' => sprintf('%u', crc32($seedKey . '|' . $index . '|' . $item)),
            ])
            ->sortBy('sort')
            ->pluck('value')
            ->values()
            ->all();
    }

    /**
     * @param array<string, int> $mix
     * @return array<int, string>
     */
    private function buildStyleSequence(int $count, array $mix, string $seedKey): array
    {
        $totalWeight = max(1, array_sum($mix));
        $base = [];
        $remainders = [];
        $allocated = 0;

        foreach ($mix as $style => $weight) {
            $exact = $count * ($weight / $totalWeight);
            $base[$style] = (int) floor($exact);
            $remainders[$style] = $exact - $base[$style];
            $allocated += $base[$style];
        }

        $remaining = $count - $allocated;
        arsort($remainders);
        foreach (array_keys($remainders) as $style) {
            if ($remaining <= 0) {
                break;
            }
            $base[$style]++;
            $remaining--;
        }

        $sequence = [];
        foreach ($base as $style => $styleCount) {
            for ($i = 0; $i < $styleCount; $i++) {
                $sequence[] = $style;
            }
        }

        return $this->seededShuffle($sequence, $seedKey);
    }

    /**
     * @param array<string, int> $mix
     * @return array<int, string>
     */
    private function buildDifficultySequence(int $count, array $mix, string $seedKey): array
    {
        return $this->buildStyleSequence($count, $mix, $seedKey);
    }

    /**
     * @param array<string, mixed> $batch
     * @param array<string, mixed> $config
     */
    private function buildPrompt(array $batch, array $config): string
    {
        $topicLines = [];
        foreach ($batch['topics'] as $item) {
            $topicLines[] = sprintf(
                '- Tema base: %s | Estilo: %s | Ângulo: %s | Dificuldade alvo: %s',
                $item['tema_base'],
                $item['estilo'],
                $item['angulo'],
                $item['dificuldade_alvo'] ?? 'Média'
            );
        }

        $styleLines = [];
        foreach ($batch['style_distribution'] as $style => $count) {
            $styleLines[] = "- {$style}: {$count}";
        }

        $difficultyLines = [];
        foreach ($batch['difficulty_distribution'] as $difficulty => $count) {
            $difficultyLines[] = "- {$difficulty}: {$count}";
        }

        $tagsLiteral = json_encode($batch['tags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $reference = (string) $batch['reference'];
        $areaLabel = (string) $batch['area_label'];
        $origin = (string) ($config['origin'] ?? 'ai_seeded');
        $status = (string) ($config['status'] ?? 'draft');
        $questionTarget = (int) $batch['question_target'];

        return <<<PROMPT
Você é um elaborador técnico de questões médicas estilo Revalida INEP.
Gere EXATAMENTE {$questionTarget} questões inéditas da 1ª fase do Revalida.

Parâmetros obrigatórios do lote:
- Área principal: {$areaLabel}
- Fase: 1ª fase
- Mistura desejada do lote:
{$this->indentLines($styleLines)}
- Mistura de dificuldade desejada:
{$this->indentLines($difficultyLines)}
- Priorize linguagem de prova oficial, contexto SUS e raciocínio clínico defensável.
- Use 5 alternativas completas (A, B, C, D, E).
- Deve haver apenas 1 alternativa correta.
- A maioria das questões deve ser Média ou Difícil. Questões fáceis devem ser minoria clara.
- Distratores devem ser plausíveis e competir com a correta na primeira leitura, mas apenas 1 resposta pode permanecer defensável após análise técnica.
- Use informação-pivô no enunciado para discriminar a melhor resposta.
- Não transforme dificuldade em obscuridade, pegadinha semântica ou ambiguidade real.
- Não crie "alternativas irmãs": duas opções do mesmo tronco diagnóstico ou da mesma base de conduta, separadas apenas por nuance escondida.
- Se a pergunta for de diagnóstico, as alternativas devem competir em diagnóstico e não repetir a mesma doença-base com roupas diferentes.
- Se a pergunta for de conduta, as alternativas devem competir em conduta e não repetir o mesmo diagnóstico-base de forma disfarçada.
- Só use alternativas da mesma família quando o enunciado trouxer um discriminador explícito, central e suficiente para sustentar uma única resposta.
- Evite repetição literal entre enunciados e entre alternativas.
- Não gere habilidades clínicas/estação prática.

Sementes temáticas para cobrir no lote:
{$this->indentLines($topicLines)}

Regras de formato:
- Retorne APENAS JSON válido.
- O array deve começar com [ e terminar com ].
- Cada questão deve seguir exatamente este formato:

[
  {
    "area": "{$areaLabel}",
    "tema": "...",
    "dificuldade": "Fácil | Média | Difícil",
    "enunciado": "...",
    "alternativas": {
      "A": "...",
      "B": "...",
      "C": "...",
      "D": "...",
      "E": "..."
    },
    "gabarito": "A",
    "comentario": "Explique por que a correta está correta e por que as demais não são a melhor resposta.",
    "origin": "{$origin}",
    "status": "{$status}",
    "reference": "{$reference}",
    "tags": {$tagsLiteral}
  }
]

PROMPT;
    }

    /**
     * @param array<int, string> $lines
     */
    private function indentLines(array $lines): string
    {
        return implode("\n", array_map(static fn (string $line): string => $line, $lines));
    }

    private function runAiGeneration(string $promptsDir, string $generatedPath, string $rejectedPath): int
    {
        $command = [
            'python3',
            base_path('tools/auto_generate_questions.py'),
            '--input-dir',
            $promptsDir,
            '--output-file',
            $generatedPath,
            '--rejected-file',
            $rejectedPath,
            '--exam',
            'revalida',
            '--phase',
            'first',
        ];

        $process = new Process($command, base_path(), $_ENV);
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->error('Falha na geração automática via Gemini.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function toRelativeBasePath(string $absolutePath): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($absolutePath, $base)) {
            return substr($absolutePath, strlen($base));
        }

        return $absolutePath;
    }

    private function normalizeText(string $value): string
    {
        $text = trim($value);
        if ($text === '') {
            return '';
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $normalized = $ascii !== false ? $ascii : $text;

        return Str::lower($normalized);
    }
}
