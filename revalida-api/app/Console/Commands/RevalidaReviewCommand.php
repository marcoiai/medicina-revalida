<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RevalidaReviewCommand extends Command
{
    protected $signature = 'revalida:review
        {input? : Arquivo JSON a revisar}
        {--run= : Run em storage/imports/generated/<run>}
        {--output-dir= : Diretório de saída da validação}
        {--limit=0 : Limita a quantidade processada}
        {--llm : Ativa auditoria semântica com IA}
        {--llm-strict : Endurece reprovações vindas do LLM}';

    protected $description = 'Revisa lotes gerados com o validador de questões do pipeline';

    public function handle(): int
    {
        $inputPath = $this->resolveInputPath();
        $aiEnabled = (bool) config('features.ai_enabled', true);
        if ($inputPath === null) {
            $this->error('Não encontrei um arquivo de entrada para revisar.');
            return self::FAILURE;
        }

        $outputDir = $this->resolveOutputDir($inputPath);
        $command = [
            'python3',
            base_path('tools/validate_questions.py'),
            $inputPath,
            '--output-dir',
            $outputDir,
        ];

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $command[] = '--limit';
            $command[] = (string) $limit;
        }

        $useLlm = (bool) $this->option('llm');
        $useLlmStrict = (bool) $this->option('llm-strict');

        if (($useLlm || $useLlmStrict) && !$aiEnabled) {
            $this->warn('AI_ENABLED=false no .env. A revisão vai seguir sem auditoria LLM.');
            $useLlm = false;
            $useLlmStrict = false;
        }

        if ($useLlm) {
            $command[] = '--llm';
        }

        if ($useLlmStrict) {
            $command[] = '--llm-strict';
        }

        $process = new Process($command, base_path(), $_ENV);
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->error('Falha na revisão do lote.');
            return self::FAILURE;
        }

        $stem = pathinfo($inputPath, PATHINFO_FILENAME);
        $approvedPath = $outputDir . DIRECTORY_SEPARATOR . $stem . '.approved.json';

        $this->info('Revisão concluída.');
        $this->line('Entrada: ' . $this->toRelativeBasePath($inputPath));
        $this->line('Saída: ' . $this->toRelativeBasePath($outputDir));
        $this->line('Aprovadas: ' . $this->toRelativeBasePath($approvedPath));

        return self::SUCCESS;
    }

    private function resolveInputPath(): ?string
    {
        $input = trim((string) ($this->argument('input') ?? ''));
        if ($input !== '') {
            $absolute = $this->makeAbsolutePath($input);
            return is_file($absolute) ? $absolute : null;
        }

        $run = trim((string) ($this->option('run') ?? ''));
        if ($run !== '') {
            $candidate = base_path('storage/imports/generated/' . $run . '/questions.generated.json');
            return is_file($candidate) ? $candidate : null;
        }

        $latestRun = $this->findLatestRunDirectory();
        if ($latestRun !== null) {
            $candidate = $latestRun . DIRECTORY_SEPARATOR . 'questions.generated.json';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $fallback = base_path('storage/imports/questions.json');
        return is_file($fallback) ? $fallback : null;
    }

    private function resolveOutputDir(string $inputPath): string
    {
        $explicit = trim((string) ($this->option('output-dir') ?? ''));
        if ($explicit !== '') {
            return $this->makeAbsolutePath($explicit);
        }

        return dirname($inputPath) . DIRECTORY_SEPARATOR . 'validation';
    }

    private function findLatestRunDirectory(): ?string
    {
        $root = base_path('storage/imports/generated');
        if (!is_dir($root)) {
            return null;
        }

        $directories = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        if ($directories === []) {
            return null;
        }

        usort($directories, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $directories[0] ?? null;
    }

    private function makeAbsolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }

    private function toRelativeBasePath(string $absolutePath): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($absolutePath, $base)) {
            return substr($absolutePath, strlen($base));
        }

        return $absolutePath;
    }
}
