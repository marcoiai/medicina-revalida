<?php

namespace App\Http\Controllers;

use App\Models\Simulation;
use Illuminate\Http\Request;

class SimulationController extends Controller
{
    public function index(Request $request)
    {
        return Simulation::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Simulation $simulation) => $this->formatSimulation($simulation));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:150',
            'area' => 'nullable|string|max:120',
            'status' => 'required|in:completed,stopped,expired',
            'started_at' => 'required|date',
            'ended_at' => 'required|date',
            'duration_seconds' => 'required|integer|min:0|max:7200',
            'elapsed_seconds' => 'required|integer|min:0|max:7200',
            'total_questions' => 'required|integer|min:0',
            'answered_questions' => 'required|integer|min:0',
            'correct_questions' => 'required|integer|min:0',
            'accuracy' => 'required|integer|min:0|max:100',
        ]);

        $simulation = Simulation::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'] ?? 'Simulado',
            'area' => $data['area'] ?? 'Todas',
            'status' => $data['status'],
            'started_at' => $data['started_at'],
            'ended_at' => $data['ended_at'],
            'duration_seconds' => $data['duration_seconds'],
            'elapsed_seconds' => $data['elapsed_seconds'],
            'total_questions' => $data['total_questions'],
            'answered_questions' => $data['answered_questions'],
            'correct_questions' => $data['correct_questions'],
            'accuracy' => $data['accuracy'],
        ]);

        return response()->json($this->formatSimulation($simulation), 201);
    }

    private function formatSimulation(Simulation $simulation): array
    {
        return [
            'id' => (string) $simulation->id,
            'user_id' => (string) $simulation->user_id,
            'title' => $simulation->title,
            'area' => $simulation->area,
            'status' => $simulation->status,
            'started_at' => optional($simulation->started_at)->toIso8601String(),
            'ended_at' => optional($simulation->ended_at)->toIso8601String(),
            'duration_seconds' => $simulation->duration_seconds,
            'elapsed_seconds' => $simulation->elapsed_seconds,
            'total_questions' => $simulation->total_questions,
            'answered_questions' => $simulation->answered_questions,
            'correct_questions' => $simulation->correct_questions,
            'accuracy' => $simulation->accuracy,
        ];
    }
}
