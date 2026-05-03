<?php

namespace App\Console\Commands;

use App\Models\Question;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use stdClass;

class CopyQuestionsFromDatabase extends Command
{
    private const GENERIC_COMMENT_PREFIX = 'gabarito definitivo oficial do inep:';

    protected $signature = 'questions:copy-database
        {database : Banco PostgreSQL de origem}
        {--category=estudo_geral : Categoria de destino (revalida ou estudo_geral)}
        {--chunk=500 : Quantidade de registros por lote}
        {--limit=0 : Limite opcional para conferência}
        {--insert-only : Apenas cria questões novas, sem atualizar as existentes}';

    protected $description = 'Copia questões de outro banco PostgreSQL para o banco atual, marcando a categoria de estudo';

    public function handle(): int
    {
        $database = trim((string) $this->argument('database'));
        $category = $this->normalizeStudyCategory((string) ($this->option('category') ?? ''));
        $chunk = max(1, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $insertOnly = (bool) $this->option('insert-only');

        if ($category === 'invalid') {
            $this->error('Categoria inválida. Use revalida ou estudo_geral.');
            return self::FAILURE;
        }

        $source = $this->makeSourceConnection($database);
        $schema = $source->getSchemaBuilder();

        if (!$schema->hasTable('questions')) {
            $this->error("A tabela questions não existe em {$database}.");
            return self::FAILURE;
        }

        $hasQuestionType = $schema->hasColumn('questions', 'question_type');
        $hasCommentSource = $schema->hasColumn('questions', 'comment_source');
        $hasOfficialAnswer = $schema->hasColumn('questions', 'official_answer');

        $total = (int) $source->table('questions')->count();
        $this->line("Lendo {$total} questão(ões) de {$database}...");

        $processed = 0;
        $created = 0;
        $updated = 0;

        $source->table('questions')
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use (
                $category,
                $hasOfficialAnswer,
                $hasQuestionType,
                $hasCommentSource,
                $limit,
                $insertOnly,
                &$processed,
                &$created,
                &$updated
            ) {
                $payloads = [];

                foreach ($rows as $row) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }

                    $payloads[] = $this->buildPayloadFromRow(
                        $row,
                        $category,
                        $hasQuestionType,
                        $hasCommentSource,
                        $hasOfficialAnswer
                    );

                    $processed++;
                }

                if ($payloads !== []) {
                    $payloads = $this->enrichMissingCommentsIfNeeded($payloads);
                }

                foreach ($payloads as $payload) {
                    if ($insertOnly) {
                        $question = Question::firstOrCreate(
                            [
                                'source_hash' => $payload['source_hash'],
                                'study_category' => $payload['study_category'],
                            ],
                            $payload
                        );
                    } else {
                        $question = Question::updateOrCreate(
                            [
                                'source_hash' => $payload['source_hash'],
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

                    if ($created + $updated > 0 && (($created + $updated) % 500 === 0)) {
                        $this->line("Processadas ".($created + $updated)." questão(ões)...");
                    }
                }

                return true;
            });

        $this->info("Cópia concluída: {$created} criadas, {$updated} atualizadas, {$processed} processadas.");

        return self::SUCCESS;
    }

    private function makeSourceConnection(string $database)
    {
        $base = config('database.connections.pgsql');
        if (!is_array($base)) {
            throw new \RuntimeException('Conexão pgsql não configurada.');
        }

        $connectionName = 'questions_copy_source';
        $base['database'] = $database;
        config(["database.connections.{$connectionName}" => $base]);
        DB::purge($connectionName);

        return DB::connection($connectionName);
    }

    private function buildPayloadFromRow(
        stdClass $row,
        string $category,
        bool $hasQuestionType,
        bool $hasCommentSource,
        bool $hasOfficialAnswer
    ): array {
        $questionType = $hasQuestionType ? trim((string) ($row->question_type ?? '')) : '';
        $questionType = $questionType === 'discursive' ? 'discursive' : 'multiple_choice';

        $item = [
            'area' => (string) ($row->area ?? ''),
            'tema' => (string) ($row->tema ?? ''),
            'dificuldade' => (string) ($row->dificuldade ?? 'Média'),
            'question_type' => $questionType,
            'study_category' => $category,
            'enunciado' => (string) ($row->enunciado ?? ''),
            'alternativa_a' => (string) ($row->alternativa_a ?? ''),
            'alternativa_b' => (string) ($row->alternativa_b ?? ''),
            'alternativa_c' => (string) ($row->alternativa_c ?? ''),
            'alternativa_d' => (string) ($row->alternativa_d ?? ''),
            'alternativa_e' => (string) ($row->alternativa_e ?? ''),
            'gabarito' => strtoupper(substr((string) ($row->gabarito ?? 'A'), 0, 1)) ?: 'A',
            'comentario' => $this->nullableString($row->comentario ?? null),
            'comment_source' => $this->resolveCommentSourceFromRow($row, $questionType, $hasCommentSource, $hasOfficialAnswer),
            'official_answer' => $hasOfficialAnswer ? $this->nullableString($row->official_answer ?? null) : null,
            'origin' => $this->nullableString($row->origin ?? null) ?? 'original',
            'status' => $this->nullableString($row->status ?? null) ?? 'draft',
            'reference' => $this->nullableString($row->reference ?? null),
            'tags' => $this->normalizeTags($row->tags ?? null),
        ];

        $item['source_hash'] = $this->buildSourceHash($item);

        return $item;
    }

    private function buildSourceHash(array $item): string
    {
        $canonical = [
            'area' => $this->normalizeText($item['area'] ?? ''),
            'tema' => $this->normalizeText($item['tema'] ?? ''),
            'dificuldade' => $this->normalizeText($item['dificuldade'] ?? ''),
            'enunciado' => $this->normalizeText($item['enunciado'] ?? ''),
            'A' => $this->normalizeText($item['alternativa_a'] ?? ''),
            'B' => $this->normalizeText($item['alternativa_b'] ?? ''),
            'C' => $this->normalizeText($item['alternativa_c'] ?? ''),
            'D' => $this->normalizeText($item['alternativa_d'] ?? ''),
            'E' => $this->normalizeText($item['alternativa_e'] ?? ''),
            'gabarito' => $this->normalizeText($item['gabarito'] ?? ''),
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeTags(mixed $tags): ?array
    {
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $tags = $decoded;
            }
        }

        if (!is_array($tags)) {
            return null;
        }

        $clean = [];
        foreach ($tags as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }

            $value = trim((string) $tag);
            if ($value !== '') {
                $clean[] = $value;
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

    private function resolveCommentSourceFromRow(
        stdClass $row,
        string $questionType,
        bool $hasCommentSource,
        bool $hasOfficialAnswer
    ): ?string {
        if ($hasCommentSource) {
            $explicit = strtolower(trim((string) ($row->comment_source ?? '')));
            if (in_array($explicit, ['official', 'ai'], true)) {
                return $explicit;
            }
        }

        if ($questionType === 'discursive' && $hasOfficialAnswer && !empty($row->official_answer)) {
            return 'official';
        }

        $origin = trim((string) ($row->origin ?? ''));
        $comment = strtolower(trim((string) ($row->comentario ?? '')));
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
     * @param array<int, array<string, mixed>> $payloads
     * @return array<int, array<string, mixed>>
     */
    private function enrichMissingCommentsIfNeeded(array $payloads): array
    {
        if (!$this->shouldUseAi()) {
            return $payloads;
        }

        $candidateIndexes = [];
        foreach ($payloads as $index => $payload) {
            $comment = strtolower(trim((string) ($payload['comentario'] ?? '')));
            if ($comment === '' || str_starts_with($comment, self::GENERIC_COMMENT_PREFIX)) {
                $candidateIndexes[] = $index;
            }
        }

        if ($candidateIndexes === []) {
            return $payloads;
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'questions_ai_in_');
        $outputPath = tempnam(sys_get_temp_dir(), 'questions_ai_out_');
        if (!$inputPath || !$outputPath) {
            return $payloads;
        }

        try {
            file_put_contents($inputPath, json_encode($payloads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

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
                return $payloads;
            }

            $decoded = json_decode((string) file_get_contents($outputPath), true);
            if (!is_array($decoded)) {
                return $payloads;
            }

            return $decoded;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    private function shouldUseAi(): bool
    {
        return (bool) config('features.ai_enabled', true)
            && strtolower(trim((string) (getenv('QUESTION_AI_PROVIDER') ?: getenv('QUESTION_COMMENT_PROVIDER') ?: 'openai'))) !== ''
            && !in_array(strtolower(trim((string) (getenv('QUESTION_AI_PROVIDER') ?: getenv('QUESTION_COMMENT_PROVIDER') ?: 'openai'))), ['0', 'false', 'no', 'off'], true);
    }

    private function resolveAiProvider(): string
    {
        $provider = strtolower(trim((string) (getenv('QUESTION_AI_PROVIDER') ?: getenv('QUESTION_COMMENT_PROVIDER') ?: 'openai')));
        return in_array($provider, ['openai', 'gemini'], true) ? $provider : 'openai';
    }

    private function normalizeStudyCategory(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'revalida' => 'revalida',
            'estudo_geral', 'estudogeral', 'geral', 'general', '' => 'estudo_geral',
            default => 'invalid',
        };
    }
}
