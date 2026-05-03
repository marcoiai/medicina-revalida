<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RevalidaGenerateCommandTest extends TestCase
{
    public function test_generate_command_creates_plan_and_prompt_batches(): void
    {
        $outputRoot = base_path('storage/testing/generated');
        $runId = 'test_run_generate';
        $runDir = $outputRoot . DIRECTORY_SEPARATOR . $runId;

        File::deleteDirectory($runDir);

        $this->artisan('revalida:generate', [
            '--amount' => 12,
            '--area' => 'clinica',
            '--batch-size' => 5,
            '--output-dir' => 'storage/testing/generated',
            '--run-id' => $runId,
            '--seed' => 12345,
        ])->assertExitCode(0);

        $planPath = $runDir . DIRECTORY_SEPARATOR . 'generation_plan.json';
        $promptsDir = $runDir . DIRECTORY_SEPARATOR . 'prompts';

        $this->assertFileExists($planPath);
        $this->assertDirectoryExists($promptsDir);

        $plan = json_decode((string) file_get_contents($planPath), true);

        $this->assertSame(12, $plan['amount']);
        $this->assertSame(12, $plan['distribution']['clinica_medica']);
        $this->assertCount(3, $plan['batches']);
        $this->assertArrayHasKey('difficulty_distribution', $plan['batches'][0]);
        $this->assertArrayHasKey('dificuldade_alvo', $plan['batches'][0]['topics'][0]);
        $this->assertFileExists($promptsDir . DIRECTORY_SEPARATOR . 'batch_001_clinica_medica.prompt.txt');
        $this->assertFileExists($promptsDir . DIRECTORY_SEPARATOR . 'batch_002_clinica_medica.prompt.txt');
        $this->assertFileExists($promptsDir . DIRECTORY_SEPARATOR . 'batch_003_clinica_medica.prompt.txt');

        $prompt = (string) file_get_contents($promptsDir . DIRECTORY_SEPARATOR . 'batch_001_clinica_medica.prompt.txt');
        $this->assertStringContainsString('Mistura de dificuldade desejada', $prompt);
        $this->assertStringContainsString('Dificuldade alvo', $prompt);
        $this->assertStringContainsString('Distratores devem ser plausíveis', $prompt);
        $this->assertStringContainsString('Não crie "alternativas irmãs"', $prompt);
        $this->assertStringContainsString('Se a pergunta for de diagnóstico, as alternativas devem competir em diagnóstico', $prompt);

        File::deleteDirectory($runDir);
    }
}
