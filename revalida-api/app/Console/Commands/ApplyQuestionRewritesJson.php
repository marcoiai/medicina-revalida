<?php

namespace App\Console\Commands;

use App\Models\Question;
use Illuminate\Console\Command;

class ApplyQuestionRewritesJson extends Command
{
    protected $signature = 'questions:apply-rewrites-json
        {file : Arquivo JSON com id e alternativas reescritas}
        {--dry-run : Apenas valida e mostra o que seria atualizado}
        {--set-status= : Define status após atualizar, ex.: reviewed}';

    protected $description = 'Aplica reescritas de alternativas/comentário em questões existentes do banco';

    public function handle(): int
    {
        $file = base_path($this->argument('file'));

        if (!file_exists($file)) {
            $this->error("Arquivo não encontrado: {$file}");
            return self::FAILURE;
        }

        $items = json_decode((string) file_get_contents($file), true);
        if (!is_array($items)) {
            $this->error('JSON inválido.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $setStatus = $this->option('set-status');
        $updated = 0;
        $skipped = 0;

        foreach ($items as $index => $item) {
            if (!is_array($item) || !isset($item['id']) || !is_numeric($item['id'])) {
                $skipped++;
                $this->warn('Item ignorado sem id válido na posição ' . ($index + 1));
                continue;
            }

            $question = Question::find((int) $item['id']);
            if (!$question) {
                $skipped++;
                $this->warn('Questão não encontrada para id ' . $item['id']);
                continue;
            }

            $alternatives = $item['rewritten_alternatives'] ?? $item['alternativas'] ?? null;
            if (!is_array($alternatives)) {
                $skipped++;
                $this->warn('Item ignorado sem alternativas válidas para id ' . $item['id']);
                continue;
            }

            foreach (['A', 'B', 'C', 'D', 'E'] as $key) {
                if (!isset($alternatives[$key]) || trim((string) $alternatives[$key]) === '') {
                    $skipped++;
                    $this->warn("Item ignorado: alternativa {$key} vazia para id " . $item['id']);
                    continue 2;
                }
            }

            $payload = [
                'alternativa_a' => (string) $alternatives['A'],
                'alternativa_b' => (string) $alternatives['B'],
                'alternativa_c' => (string) $alternatives['C'],
                'alternativa_d' => (string) $alternatives['D'],
                'alternativa_e' => (string) $alternatives['E'],
            ];

            $comment = $item['rewritten_comment'] ?? $item['comentario'] ?? null;
            if ($comment !== null && trim((string) $comment) !== '') {
                $payload['comentario'] = (string) $comment;
            }

            if ($setStatus) {
                $payload['status'] = (string) $setStatus;
            } elseif (
                isset($item['rewrite_status'])
                && in_array((string) $item['rewrite_status'], ['rewritten', 'reviewed', 'manual_review'], true)
            ) {
                $payload['status'] = (string) $item['rewrite_status'] === 'reviewed' ? 'reviewed' : $question->status;
            } elseif (isset($item['status']) && trim((string) $item['status']) !== '') {
                $payload['status'] = (string) $item['status'];
            }

            $current = [
                'alternativa_a' => (string) $question->alternativa_a,
                'alternativa_b' => (string) $question->alternativa_b,
                'alternativa_c' => (string) $question->alternativa_c,
                'alternativa_d' => (string) $question->alternativa_d,
                'alternativa_e' => (string) $question->alternativa_e,
                'comentario' => (string) ($question->comentario ?? ''),
            ];
            $next = [
                'alternativa_a' => $payload['alternativa_a'],
                'alternativa_b' => $payload['alternativa_b'],
                'alternativa_c' => $payload['alternativa_c'],
                'alternativa_d' => $payload['alternativa_d'],
                'alternativa_e' => $payload['alternativa_e'],
                'comentario' => (string) ($payload['comentario'] ?? $question->comentario ?? ''),
            ];

            if ($current === $next && !$setStatus) {
                $skipped++;
                $this->line('Sem mudanças detectadas para id=' . $question->id . '.');
                continue;
            }

            if ($dryRun) {
                $this->line('DRY-RUN id=' . $question->id . ' atualizado.');
                $updated++;
                continue;
            }

            $question->fill($payload);
            $question->save();
            $updated++;
        }

        $this->info("Concluído: {$updated} atualizadas, {$skipped} ignoradas.");

        return self::SUCCESS;
    }
}
