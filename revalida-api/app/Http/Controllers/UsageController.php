<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\QuestionAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UsageController extends Controller
{
    public function overview(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'period_start' => now()->startOfMonth()->toDateString(),
            'categories' => [
                'revalida' => $this->categoryUsage($user, 'revalida'),
                'estudo_geral' => $this->categoryUsage($user, 'estudo_geral'),
            ],
        ]);
    }

    public function attempt(Request $request, Question $question)
    {
        $user = $request->user();
        $data = $request->validate([
            'study_category' => 'required|in:revalida,estudo_geral',
            'is_correct' => 'nullable|boolean',
        ]);

        $category = (string) $data['study_category'];

        if (($question->study_category ?? 'revalida') !== $category) {
            throw ValidationException::withMessages([
                'study_category' => ['Categoria não corresponde à questão'],
            ]);
        }

        $periodStart = now()->startOfMonth()->toDateString();

        $limit = $this->resolveLimitForCategory($user, $category);
        $limit = max(0, (int) $limit);

        // If schema isn't migrated yet, we can't enforce properly; fail open.
        if (!Schema::hasTable('question_attempts')) {
            return response()->json([
                'enforced' => false,
                'limit' => $limit,
                'used' => 0,
                'remaining' => $limit,
            ]);
        }

        $result = DB::transaction(function () use ($user, $question, $category, $periodStart, $data, $limit) {
            $used = QuestionAttempt::query()
                ->where('user_id', $user->id)
                ->where('study_category', $category)
                ->where('period_start', $periodStart)
                ->count();

            $alreadyAttempted = QuestionAttempt::query()
                ->where('user_id', $user->id)
                ->where('question_id', $question->id)
                ->where('study_category', $category)
                ->where('period_start', $periodStart)
                ->exists();

            if (!$alreadyAttempted && $limit > 0 && $used >= $limit) {
                return [
                    'allowed' => false,
                    'used' => $used,
                    'remaining' => 0,
                ];
            }

            if (!$alreadyAttempted) {
                QuestionAttempt::create([
                    'user_id' => $user->id,
                    'question_id' => $question->id,
                    'study_category' => $category,
                    'period_start' => $periodStart,
                    'attempted_at' => now(),
                    'is_correct' => array_key_exists('is_correct', $data) ? $data['is_correct'] : null,
                ]);
                $used += 1;
            }

            $remaining = $limit > 0 ? max(0, $limit - $used) : 0;

            return [
                'allowed' => true,
                'used' => $used,
                'remaining' => $remaining,
            ];
        });

        if (!$result['allowed']) {
            return response()->json([
                'message' => 'Limite mensal do plano atingido.',
                'enforced' => true,
                'limit' => $limit,
                'used' => $result['used'],
                'remaining' => 0,
            ], 429);
        }

        return response()->json([
            'enforced' => true,
            'limit' => $limit,
            'used' => $result['used'],
            'remaining' => $result['remaining'],
        ]);
    }

    private function categoryUsage(User $user, string $category): array
    {
        $periodStart = now()->startOfMonth()->toDateString();
        $limit = max(0, (int) $this->resolveLimitForCategory($user, $category));

        if (!Schema::hasTable('question_attempts')) {
            return [
                'limit' => $limit,
                'used' => 0,
                'remaining' => $limit,
                'enforced' => false,
            ];
        }

        $used = QuestionAttempt::query()
            ->where('user_id', $user->id)
            ->where('study_category', $category)
            ->where('period_start', $periodStart)
            ->count();

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit > 0 ? max(0, $limit - $used) : 0,
            'enforced' => true,
        ];
    }

    private function resolveLimitForCategory(User $user, string $category): int
    {
        $code = null;
        $plansByCategory = is_array($user->current_plans_by_category ?? null) ? $user->current_plans_by_category : null;
        if ($plansByCategory && isset($plansByCategory[$category]['code'])) {
            $code = (string) $plansByCategory[$category]['code'];
        } elseif ($category === 'revalida') {
            $code = (string) ($user->current_plan_code ?? '');
        }

        $code = Str::lower(trim($code ?? ''));

        if ($code !== '') {
            foreach ((array) config('billing.plans', []) as $plan) {
                $planCode = Str::lower((string) ($plan['code'] ?? ''));
                $planCategory = (string) ($plan['category'] ?? 'revalida');
                if ($planCode === $code && $planCategory === $category) {
                    return (int) ($plan['monthly_question_limit'] ?? 0);
                }
            }
        }

        return (int) ($user->monthly_question_limit ?? 0);
    }
}

