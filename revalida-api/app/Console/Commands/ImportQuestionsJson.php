<?php

namespace App\Console\Commands;

use App\Models\Question;
use App\Models\QuestionSource;
use Illuminate\Console\Command;

class ImportQuestionsJson extends Command
{
    protected $signature = 'questions:import-json
        {file=storage/imports/questions.json}
        {--category= : Força a categoria de estudo (revalida ou estudo_geral)}';
    protected $description = 'Importa questões JSON para o banco com deduplicação e metadados de fonte';

    public function handle(): int
    {
        $forcedCategory = $this->normalizeStudyCategory((string) ($this->option('category') ?? ''));
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
                'official_answer' => isset($item['official_answer']) ? (string) $item['official_answer'] : null,
                'origin' => (string) ($item['origin'] ?? 'official_based'),
                'status' => (string) ($item['status'] ?? 'draft'),
                'reference' => isset($item['reference']) ? (string) $item['reference'] : $this->buildReference($item, $source),
                'tags' => $this->normalizeTags($item['tags'] ?? null),
            ];

            $question = Question::updateOrCreate(
                ['source_hash' => $sourceHash],
                $payload
            );

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
