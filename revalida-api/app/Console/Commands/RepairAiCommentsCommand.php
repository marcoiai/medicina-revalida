<?php

namespace App\Console\Commands;

use App\Models\Question;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class RepairAiCommentsCommand extends Command
{
    private const GENERIC_COMMENT_PATTERNS = [
        '/^errad[oa]\b/u',
        '/^gabarito(\s+definitivo)?(\s+oficial)?(\s+do\s+inep)?\b/u',
        '/^padr[aã]o\s+de\s+resposta\b/u',
        '/^resposta\s+oficial\b/u',
        '/^cancelad[oa]\b/u',
    ];

    protected $signature = 'questions:repair-ai-comments
        {--category=all : Filtra por categoria (all, revalida ou estudo_geral)}
        {--limit=0 : Limita a quantidade de questões reparadas}
        {--provider=openai : Provedor de IA (openai ou gemini)}
        {--dry-run : Mostra o que seria reparado sem salvar no banco}';

    protected $description = 'Reescreve comentários genéricos ou ausentes usando IA, sem alterar enunciado ou alternativas';

    public function handle(): int
    {
        $category = $this->normalizeCategory((string) $this->option('category'));
        if ($category === 'invalid') {
            $this->error('Categoria inválida. Use all, revalida ou estudo_geral.');
            return self::FAILURE;
        }

        $provider = $this->normalizeProvider((string) $this->option('provider'));
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Question::query()->orderBy('id');
        if ($category !== 'all') {
            $query->where('study_category', $category);
        }

        $selected = [];
        $processed = 0;

        $query->chunkById(500, function ($rows) use (&$selected, &$processed, $limit) {
            foreach ($rows as $question) {
                $processed++;
                if ($limit > 0 && count($selected) >= $limit) {
                    return false;
                }

                if (!$this->needsRepair($question)) {
                    continue;
                }

                $selected[] = $this->serializeQuestion($question);
            }

            return true;
        });

        if ($selected === []) {
            $this->info('Nenhuma questão elegível para reparo de comentário.');
            return self::SUCCESS;
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'questions_repair_in_');
        $outputPath = tempnam(sys_get_temp_dir(), 'questions_repair_out_');
        if (!$inputPath || !$outputPath) {
            $this->error('Não foi possível criar arquivos temporários.');
            return self::FAILURE;
        }

        try {
            File::put($inputPath, json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $python = is_file(base_path('.venv/bin/python3'))
                ? base_path('.venv/bin/python3')
                : 'python3';

            $process = new Process([
                $python,
                base_path('tools/enrich_official_comments.py'),
                '--input',
                $inputPath,
                '--output',
                $outputPath,
                '--provider',
                $provider,
                '--all-missing',
            ], base_path(), array_merge($_ENV, [
                'QUESTION_COMMENT_PROVIDER' => $provider,
                'QUESTION_AI_PROVIDER' => $provider,
            ]));

            $process->setTimeout(null);
            $process->run(function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });

            if (!$process->isSuccessful() || !is_file($outputPath)) {
                $this->error('Falha ao gerar comentários via IA.');
                return self::FAILURE;
            }

            $repaired = json_decode((string) file_get_contents($outputPath), true);
            if (!is_array($repaired)) {
                $this->error('Resposta da IA inválida.');
                return self::FAILURE;
            }

            $updated = 0;
            foreach ($repaired as $item) {
                if (!is_array($item) || !isset($item['id']) || !is_numeric($item['id'])) {
                    continue;
                }

                $comment = trim((string) ($item['comentario'] ?? ''));
                if ($comment === '') {
                    continue;
                }

                if ($dryRun) {
                    $this->line('DRY-RUN id=' . (int) $item['id'] . ' -> comentário reparado.');
                    $updated++;
                    continue;
                }

                Question::whereKey((int) $item['id'])->update([
                    'comentario' => $comment,
                    'comment_source' => 'ai',
                ]);
                $updated++;
            }

            $this->info("Concluído: {$updated} comentário(s) reparado(s) de {$processed} questão(ões) avaliadas.");

            return self::SUCCESS;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    private function needsRepair(Question $question): bool
    {
        $comment = strtolower(trim((string) ($question->comentario ?? '')));
        if ($comment === '') {
            return true;
        }

        foreach (self::GENERIC_COMMENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $comment)) {
                return true;
            }
        }

        if (str_contains($comment, ' gabarito ') && count(preg_split('/\s+/', $comment) ?: []) <= 12) {
            return true;
        }

        return false;
    }

    private function serializeQuestion(Question $question): array
    {
        return [
            'id' => $question->id,
            'enunciado' => $question->enunciado,
            'alternativas' => [
                'A' => $question->alternativa_a,
                'B' => $question->alternativa_b,
                'C' => $question->alternativa_c,
                'D' => $question->alternativa_d,
                'E' => $question->alternativa_e,
            ],
            'gabarito' => $question->gabarito,
            'comentario' => $question->comentario,
            'reference' => $question->reference,
            'origin' => $question->origin,
            'question_type' => $question->question_type,
        ];
    }

    private function normalizeCategory(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            'all', '' => 'all',
            'revalida' => 'revalida',
            'estudo_geral', 'estudogeral', 'geral', 'general' => 'estudo_geral',
            default => 'invalid',
        };
    }

    private function normalizeProvider(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['openai', 'gemini'], true) ? $value : 'openai';
    }
}
