<?php

namespace App\Console\Commands;

use App\Models\Question;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ExportQuestionsForRewrite extends Command
{
    protected $signature = 'questions:export-for-rewrite
        {--out=storage/imports/questions.for-rewrite.json : Arquivo JSON de saída}
        {--max-words=3 : Considera curta a alternativa com até este número de palavras}
        {--min-short=4 : Quantidade mínima de alternativas curtas para exportar a questão}
        {--limit=0 : Limita a quantidade exportada}
        {--status= : Filtra por status}
        {--ids= : Lista de IDs separados por vírgula}
        {--exclude-temas= : Lista de termos sensíveis para excluir ao buscar em tema/enunciado}
        {--exclude-areas= : Lista de áreas para excluir}
        {--only-origin= : Lista de origins permitidos, ex.: official_based}';

    protected $description = 'Exporta do banco questões com alternativas curtas/telegráficas para reescrita';

    public function handle(): int
    {
        $maxWords = max(1, (int) $this->option('max-words'));
        $minShort = max(1, (int) $this->option('min-short'));
        $limit = max(0, (int) $this->option('limit'));
        $status = $this->option('status');
        $ids = $this->parseIds((string) ($this->option('ids') ?? ''));
        $excludedTemaTerms = $this->parseTerms((string) ($this->option('exclude-temas') ?? ''));
        $excludedAreas = $this->parseTerms((string) ($this->option('exclude-areas') ?? ''));
        $allowedOrigins = $this->parseTerms((string) ($this->option('only-origin') ?? ''));

        $query = Question::query()
            ->where('question_type', 'multiple_choice')
            ->orderBy('id');

        if ($status) {
            $query->where('status', $status);
        }

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $items = [];

        foreach ($query->cursor() as $question) {
            if ($allowedOrigins !== [] && !$this->matchesAnyTerm((string) $question->origin, $allowedOrigins)) {
                continue;
            }

            if ($excludedAreas !== [] && $this->matchesArea((string) $question->area, $excludedAreas)) {
                continue;
            }

            if (
                $excludedTemaTerms !== []
                && $this->matchesAnyTerm(
                    trim((string) $question->tema . ' ' . (string) $question->enunciado),
                    $excludedTemaTerms
                )
            ) {
                continue;
            }

            $alternatives = [
                'A' => (string) $question->alternativa_a,
                'B' => (string) $question->alternativa_b,
                'C' => (string) $question->alternativa_c,
                'D' => (string) $question->alternativa_d,
                'E' => (string) $question->alternativa_e,
            ];

            $wordCounts = [];
            $shortAlternatives = [];
            $charLengths = [];

            foreach ($alternatives as $key => $text) {
                $count = $this->countWords($text);
                $wordCounts[$key] = $count;
                $charLengths[$key] = Str::length(trim($text));

                if ($count <= $maxWords) {
                    $shortAlternatives[] = $key;
                }
            }

            if (count($shortAlternatives) < $minShort) {
                continue;
            }

            $items[] = [
                'id' => $question->id,
                'area' => $question->area,
                'tema' => $question->tema,
                'dificuldade' => $question->dificuldade,
                'enunciado' => $question->enunciado,
                'alternativas' => $alternatives,
                'original_alternatives' => $alternatives,
                'rewritten_alternatives' => $alternatives,
                'gabarito' => $question->gabarito,
                'comentario' => $question->comentario,
                'original_comment' => $question->comentario,
                'rewritten_comment' => null,
                'status' => $question->status,
                'origin' => $question->origin,
                'reference' => $question->reference,
                'source_hash' => $question->source_hash,
                'rewrite_status' => 'pending',
                'rewrite_version' => 1,
                'rewrite_notes' => [],
                'risk_flags' => [],
                'answer_changed' => false,
                'rewrite_reason' => [
                    'type' => 'telegraphic_alternatives',
                    'max_words' => $maxWords,
                    'min_short' => $minShort,
                    'short_alternatives' => $shortAlternatives,
                    'word_counts' => $wordCounts,
                    'char_lengths' => $charLengths,
                    'excluded_filters_applied' => [
                        'exclude_temas' => $excludedTemaTerms,
                        'exclude_areas' => $excludedAreas,
                        'only_origin' => $allowedOrigins,
                    ],
                ],
            ];

            if ($limit > 0 && count($items) >= $limit) {
                break;
            }
        }

        $outputPath = base_path((string) $this->option('out'));
        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $outputPath,
            json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->info('Questões exportadas: ' . count($items));
        $this->line('Arquivo: ' . $outputPath);

        return self::SUCCESS;
    }

    private function countWords(string $text): int
    {
        preg_match_all('/\b[\pL\pNº°\/-]+\b/u', trim($text), $matches);
        return count($matches[0]);
    }

    /**
     * @return string[]
     */
    private function parseTerms(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = array_map(
            fn (string $term): string => $this->normalizeText($term),
            explode(',', $raw)
        );

        return array_values(array_filter($parts, static fn (string $term): bool => $term !== ''));
    }

    /**
     * @param string[] $terms
     */
    private function matchesAnyTerm(string $text, array $terms): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        foreach ($terms as $term) {
            if ($term !== '' && str_contains($normalized, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $terms
     */
    private function matchesArea(string $area, array $terms): bool
    {
        $normalized = $this->normalizeText($area);
        if ($normalized === '') {
            return false;
        }

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            if (strlen($term) <= 3) {
                if (
                    $normalized === $term
                    || preg_match('/\b' . preg_quote($term, '/') . '\b/u', $normalized) === 1
                ) {
                    return true;
                }

                continue;
            }

            if (str_contains($normalized, $term)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $normalized = $ascii !== false ? $ascii : $text;

        return Str::lower($normalized);
    }

    /**
     * @return int[]
     */
    private function parseIds(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $ids = array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', $raw)
        );

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }
}
