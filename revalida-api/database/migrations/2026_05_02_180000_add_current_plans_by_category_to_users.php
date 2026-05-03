<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('current_plans_by_category')->nullable()->after('current_plan_code');
        });

        $normalizeLegacyPlan = function (mixed $planCode): string {
            return match ((string) $planCode) {
                'pro', 'pro_geral' => 'pro',
                'mentoria', 'mentoria_geral' => 'mentoria',
                default => 'free',
            };
        };

        DB::table('users')
            ->select('id', 'current_plan_code', 'plan_activated_at')
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($normalizeLegacyPlan) {
                foreach ($users as $user) {
                    $legacy = $normalizeLegacyPlan($user->current_plan_code);

                    $revalidaPlanCode = $legacy;
                    $geralPlanCode = match ($legacy) {
                        'pro' => 'pro_geral',
                        'mentoria' => 'mentoria_geral',
                        default => 'free_geral',
                    };

                    $activatedAt = $user->plan_activated_at
                        ? $user->plan_activated_at
                        : now()->toIso8601String();

                    DB::table('users')
                        ->where('id', (int) $user->id)
                        ->update([
                            'current_plans_by_category' => json_encode([
                                'revalida' => [
                                    'code' => $revalidaPlanCode,
                                    'activated_at' => $activatedAt,
                                ],
                                'estudo_geral' => [
                                    'code' => $geralPlanCode,
                                    'activated_at' => $activatedAt,
                                ],
                            ], JSON_UNESCAPED_UNICODE),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('current_plans_by_category');
        });
    }
};
