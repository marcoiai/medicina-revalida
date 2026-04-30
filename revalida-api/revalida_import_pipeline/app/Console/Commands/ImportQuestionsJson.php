<?php

namespace App\Console\Commands;

use App\Models\Question;
use Illuminate\Console\Command;

class ImportQuestionsJson extends Command
{
    protected $signature = 'questions:import-json {path= : Optional JSON path}';
    protected $description = 'Import questions from JSON into the questions table';

    public function handle(): int
    {
        $path = $this->argument('path') ?: storage_path('imports/questions.json');

        if (!file_exists($path)) {
            $this->error("Arquivo não encontrado: {$path}");
            return self::FAILURE;
        }

        $items = json_decode(file_get_contents($path), true);

        if (!is_array($items)) {
            $this->error("JSON inválido ou formato inesperado.");
            return self::FAILURE;
        }

        $count = 0;

        foreach ($items as $item) {
            Question::create([
                'area' => $item['area'],
                'tema' => $item['tema'],
                'dificuldade' => $item['dificuldade'] ?? 'Média',
                'enunciado' => $item['enunciado'],
                'alternativa_a' => $item['alternativas']['A'],
                'alternativa_b' => $item['alternativas']['B'],
                'alternativa_c' => $item['alternativas']['C'],
                'alternativa_d' => $item['alternativas']['D'],
                'alternativa_e' => $item['alternativas']['E'],
                'gabarito' => $item['gabarito'],
                'comentario' => $item['comentario'] ?? null,
                'origin' => $item['origin'] ?? 'official_based',
                'status' => $item['status'] ?? 'draft',
                'reference' => $item['reference'] ?? null,
                'tags' => $item['tags'] ?? null,
            ]);

            $count++;
        }

        $this->info("{$count} questões importadas.");
        return self::SUCCESS;
    }
}
