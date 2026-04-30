<?php

namespace App\Http\Controllers;

use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class QuestionController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 2000);
        $perPage = max(1, min($perPage, 2000));

        /** @var LengthAwarePaginator $questions */
        $questions = Question::query()
            ->with('source')
            ->orderBy('id')
            ->paginate($perPage);

        $questions->getCollection()->transform(function ($q) {
            return $this->formatQuestion($q);
        });

        return response()->json($questions);
    }

    public function summary()
    {
        return response()->json([
            'total_questions' => Question::count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'area' => 'required|string|max:100',
            'tema' => 'required|string|max:150',
            'dificuldade' => 'nullable|string|max:50',
            'enunciado' => 'required|string',
            'alternativas.A' => 'required|string',
            'alternativas.B' => 'required|string',
            'alternativas.C' => 'required|string',
            'alternativas.D' => 'required|string',
            'alternativas.E' => 'required|string',
            'gabarito' => 'required|in:A,B,C,D,E',
            'comentario' => 'nullable|string',
        ]);

        $q = Question::create([
            'area' => $data['area'],
            'tema' => $data['tema'],
            'dificuldade' => $data['dificuldade'] ?? 'Média',
            'enunciado' => $data['enunciado'],
            'alternativa_a' => $data['alternativas']['A'],
            'alternativa_b' => $data['alternativas']['B'],
            'alternativa_c' => $data['alternativas']['C'],
            'alternativa_d' => $data['alternativas']['D'],
            'alternativa_e' => $data['alternativas']['E'],
            'gabarito' => $data['gabarito'],
            'comentario' => $data['comentario'] ?? null,
        ]);

        return response()->json($this->formatQuestion($q), 201);
    }

    private function formatQuestion(Question $q): array
    {
        return [
            'id' => $q->id,
            'area' => $q->area,
            'tema' => $q->tema,
            'dificuldade' => $q->dificuldade,
            'enunciado' => $q->enunciado,
            'alternativas' => [
                'A' => $q->alternativa_a,
                'B' => $q->alternativa_b,
                'C' => $q->alternativa_c,
                'D' => $q->alternativa_d,
                'E' => $q->alternativa_e,
            ],
            'gabarito' => $q->gabarito,
            'comentario' => $q->comentario,
            'origin' => $q->origin,
            'status' => $q->status,
            'reference' => $q->reference,
            'tags' => $q->tags,
            'question_source_id' => $q->question_source_id,
            'source' => $q->source ? [
                'id' => $q->source->id,
                'name' => $q->source->name,
                'institution' => $q->source->institution,
                'exam' => $q->source->exam,
                'year' => $q->source->year,
                'url' => $q->source->url,
            ] : null,
        ];
    }
}
