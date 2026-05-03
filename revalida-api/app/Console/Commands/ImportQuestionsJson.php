<?php

namespace App\Console\Commands;

use App\Models\Question;
use App\Models\QuestionSource;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ImportQuestionsJson extends Command
{
    private const GENERIC_COMMENT_PREFIX = 'gabarito definitivo oficial do inep:';

    protected $signature = 'questions:import-json
        {file=storage/imports/questions.json}
        {--category= : Força a categoria de estudo (revalida ou estudo_geral)}
        {--insert-only : Apenas cria questões novas, sem atualizar as existentes}';
    protected $description = 'Importa questões JSON para o banco com deduplicação e metadados de fonte';

    public function handle(): int
    {
        $forcedCategory = $this->normalizeStudyCategory((string) ($this->option('category') ?? ''));
        $insertOnly = (bool) $this->option('insert-only');
        if ($forcedCategory === 'invalid') {
            $this->error('Categoria inválida. Use revalida ou estudo_geral.');
            return self::FAILURE;
        }

        $file = base_path($this->argument('file'));

        if (!file_exists($file)) {
            $this->error("Arquivo não encontrado: $file");
            return self::FAILURE;
        }

        $items = json_decode((string) file_get_contents($file), true);

        if (!is_array($items)) {
            $this->error('JSON inválido.');
            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        $items = $this->enrichMissingCommentsIfNeeded($items);

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $alternativas = $item['alternativas'] ?? [];
            if (!is_array($alternativas)) {
                $skipped++;
                continue;
            }

            $source = $this->resolveSource($item);
            $sourceHash = $this->buildSourceHash($item);
            $payload = [
                'question_source_id' => $source?->id,
                'source_hash' => $sourceHash,
                'area' => (string) ($item['area'] ?? ''),
                'tema' => (string) ($item['tema'] ?? ''),
                'dificuldade' => (string) ($item['dificuldade'] ?? 'Média'),
                'question_type' => (string) ($item['question_type'] ?? 'multiple_choice'),
                'study_category' => $this->resolveStudyCategory($item, $source, $forcedCategory),
                'enunciado' => (string) ($item['enunciado'] ?? ''),
                'alternativa_a' => (string) ($alternativas['A'] ?? ''),
                'alternativa_b' => (string) ($alternativas['B'] ?? ''),
                'alternativa_c' => (string) ($alternativas['C'] ?? ''),
                'alternativa_d' => (string) ($alternativas['D'] ?? ''),
                'alternativa_e' => (string) ($alternativas['E'] ?? ''),
                'gabarito' => (string) ($item['gabarito'] ?? 'A'),
                'comentario' => isset($item['comentario']) ? (string) $item['comentario'] : null,
                'comment_source' => $this->resolveCommentSource($item),
                'official_answer' => isset($item['official_answer']) ? (string) $item['official_answer'] : null,
                'origin' => (string) ($item['origin'] ?? 'official_based'),
                'status' => (string) ($item['status'] ?? 'draft'),
                'reference' => isset($item['reference']) ? (string) $item['reference'] : $this->buildReference($item, $source),
                'tags' => $this->normalizeTags($item['tags'] ?? null),
            ];

            if ($insertOnly) {
                $question = Question::firstOrCreate(
                    [
                        'source_hash' => $sourceHash,
                        'study_category' => $payload['study_category'],
                    ],
                    $payload
                );
            } else {
                $question = Question::updateOrCreate(
                    [
                        'source_hash' => $sourceHash,
                        'study_category' => $payload['study_category'],
                    ],
                    $payload
                );
            }

            if ($question->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }

            if (($index + 1) % 100 === 0) {
                $this->line("Processadas ".($index + 1)." questões...");
            }
        }

        $this->info("Importação concluída: {$created} criadas, {$updated} atualizadas, {$skipped} ignoradas.");

        return self::SUCCESS;
    }

    private function resolveSource(array $item): ?QuestionSource
    {
        $sourceData = $item['question_source'] ?? $item['source'] ?? null;

        if (!is_array($sourceData) || empty($sourceData)) {
            return null;
        }

        $normalized = [
            'name' => trim((string) ($sourceData['name'] ?? '')),
            'institution' => $this->nullableString($sourceData['institution'] ?? null),
            'exam' => $this->nullableString($sourceData['exam'] ?? null),
            'year' => isset($sourceData['year']) && is_numeric($sourceData['year']) ? (int) $sourceData['year'] : null,
            'url' => $this->nullableString($sourceData['url'] ?? null),
            'file_path' => $this->nullableString($sourceData['file_path'] ?? null),
        ];

        if ($normalized['name'] === '' && $normalized['url'] === null) {
            return null;
        }

        $lookup = $normalized['url']
            ? ['url' => $normalized['url']]
            : ['name' => $normalized['name']];

        return QuestionSource::updateOrCreate($lookup, $normalized);
    }

    private function buildReference(array $item, ?QuestionSource $source): ?string
    {
        $parts = array_filter([
            $item['reference'] ?? null,
            $source?->institution,
            $source?->exam,
            $source?->year,
            $source?->url,
        ], fn ($value) => $value !== null && $value !== '');

        if (!$parts) {
            return null;
        }

        return implode(' | ', array_map(fn ($value) => (string) $value, $parts));
    }

    private function buildSourceHash(array $item): string
    {
        $alternativas = $item['alternativas'] ?? [];
        $canonical = [
            'area' => $this->normalizeText($item['area'] ?? ''),
            'tema' => $this->normalizeText($item['tema'] ?? ''),
            'dificuldade' => $this->normalizeText($item['dificuldade'] ?? ''),
            'enunciado' => $this->normalizeText($item['enunciado'] ?? ''),
            'A' => $this->normalizeText($alternativas['A'] ?? ''),
            'B' => $this->normalizeText($alternativas['B'] ?? ''),
            'C' => $this->normalizeText($alternativas['C'] ?? ''),
            'D' => $this->normalizeText($alternativas['D'] ?? ''),
            'E' => $this->normalizeText($alternativas['E'] ?? ''),
            'gabarito' => $this->normalizeText($item['gabarito'] ?? ''),
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeTags(mixed $tags): ?array
    {
        if (!is_array($tags)) {
            return null;
        }

        $clean = [];
        foreach ($tags as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }

            $tag = trim((string) $tag);
            if ($tag !== '') {
                $clean[] = $tag;
            }
        }

        return $clean ?: null;
    }

    private function normalizeText(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? $text : null;
    }

    private function resolveCommentSource(array $item): ?string
    {
        $explicit = strtolower(trim((string) ($item['comment_source'] ?? '')));
        if (in_array($explicit, ['official', 'ai'], true)) {
            return $explicit;
        }

        $questionType = strtolower(trim((string) ($item['question_type'] ?? 'multiple_choice')));
        if ($questionType === 'discursive' && !empty($item['official_answer'])) {
            return 'official';
        }

        $origin = trim((string) ($item['origin'] ?? ''));
        $comment = strtolower(trim((string) ($item['comentario'] ?? '')));
        if (
            $questionType === 'multiple_choice'
            && $origin === 'official_verbatim'
            && $comment !== ''
            && !str_starts_with($comment, self::GENERIC_COMMENT_PREFIX)
        ) {
            return 'ai';
        }

        return null;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, mixed>
     */
    private function enrichMissingCommentsIfNeeded(array $items): array
    {
        if (!$this->shouldUseAi()) {
            return $items;
        }

        $needsEnrichment = false;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $comment = strtolower(trim((string) ($item['comentario'] ?? '')));
            if ($comment === '' || str_starts_with($comment, self::GENERIC_COMMENT_PREFIX)) {
                $needsEnrichment = true;
                break;
            }
        }

        if (!$needsEnrichment) {
            return $items;
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'questions_ai_in_');
        $outputPath = tempnam(sys_get_temp_dir(), 'questions_ai_out_');
        if (!$inputPath || !$outputPath) {
            return $items;
        }

        try {
            file_put_contents($inputPath, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $process = new Process([
                is_file(base_path('.venv/bin/python3')) ? base_path('.venv/bin/python3') : 'python3',
                base_path('tools/enrich_official_comments.py'),
                '--input',
                $inputPath,
                '--output',
                $outputPath,
                '--provider',
                $this->resolveAiProvider(),
                '--all-missing',
            ], base_path(), $_ENV);

            $process->setTimeout(null);
            $process->run(function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });

            if (!$process->isSuccessful() || !is_file($outputPath)) {
                $this->warn('Não foi possível enriquecer comentários via IA; seguindo sem preenchimento.');
                return $items;
            }

            $decoded = json_decode((string) file_get_contents($outputPath), true);
            return is_array($decoded) ? $decoded : $items;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    private function shouldUseAi(): bool
    {
        $flag = strtolower(trim((string) (getenv('AI_ENABLED') ?: 'true')));
        if (in_array($flag, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        $provider = strtolower(trim((string) (getenv('QUESTION_AI_PROVIDER') ?: getenv('QUESTION_COMMENT_PROVIDER') ?: 'openai')));
        return in_array($provider, ['openai', 'gemini'], true);
    }

    private function resolveAiProvider(): string
    {
        $provider = strtolower(trim((string) (getenv('QUESTION_AI_PROVIDER') ?: getenv('QUESTION_COMMENT_PROVIDER') ?: 'openai')));
        return in_array($provider, ['openai', 'gemini'], true) ? $provider : 'openai';
    }

    private function resolveStudyCategory(array $item, ?QuestionSource $source, string $forcedCategory): string
    {
        if ($forcedCategory !== '') {
            return $forcedCategory;
        }

        $explicit = $this->normalizeStudyCategory((string) ($item['study_category'] ?? ''));
        if ($explicit !== '' && $explicit !== 'invalid') {
            return $explicit;
        }

        $hints = strtolower(implode(' ', array_filter([
            $item['reference'] ?? null,
            $item['origin'] ?? null,
            $source?->name,
            $source?->institution,
            $source?->exam,
            $source?->url,
            $source?->file_path,
        ], fn ($value) => $value !== null && $value !== '')));

        return str_contains($hints, 'revalida') ? 'revalida' : 'estudo_geral';
    }

    private function normalizeStudyCategory(string $value): string
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return '';
        }

        return match ($normalized) {
            'revalida' => 'revalida',
            'estudo_geral', 'estudogeral', 'geral', 'general' => 'estudo_geral',
            default => 'invalid',
        };
    }
}
