<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RevalidaImportCommand extends Command
{
    protected $signature = 'revalida:import
        {input? : Arquivo JSON a importar}
        {--run= : Run em storage/imports/generated/<run>}
        {--approved : Prefere o arquivo aprovado da revisão}';

    protected $description = 'Importa para o banco um lote gerado/revisado no fluxo Revalida';

    public function handle(): int
    {
        $inputPath = $this->resolveInputPath();
        if ($inputPath === null) {
            $this->error('Não encontrei um arquivo para importar.');
            return self::FAILURE;
        }

        $relativePath = $this->toRelativeBasePath($inputPath);
        $this->line('Importando: ' . $relativePath);

        return $this->call('questions:import-json', [
            'file' => $relativePath,
            '--category' => 'revalida',
        ]);
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
            $runDir = base_path('storage/imports/generated/' . $run);
            return $this->resolveFromRunDirectory($runDir);
        }

        $latestRun = $this->findLatestRunDirectory();
        if ($latestRun !== null) {
            $resolved = $this->resolveFromRunDirectory($latestRun);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $fallbackApproved = base_path('storage/imports/validation/questions.approved.json');
        if (is_file($fallbackApproved)) {
            return $fallbackApproved;
        }

        $fallbackRaw = base_path('storage/imports/questions.json');
        return is_file($fallbackRaw) ? $fallbackRaw : null;
    }

    private function resolveFromRunDirectory(string $runDir): ?string
    {
        if (!is_dir($runDir)) {
            return null;
        }

        $approvedPreferred = (bool) $this->option('approved');
        $approved = $runDir . DIRECTORY_SEPARATOR . 'validation' . DIRECTORY_SEPARATOR . 'questions.generated.approved.json';
        $raw = $runDir . DIRECTORY_SEPARATOR . 'questions.generated.json';

        if ($approvedPreferred && is_file($approved)) {
            return $approved;
        }

        if (is_file($approved)) {
            return $approved;
        }

        return is_file($raw) ? $raw : null;
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
