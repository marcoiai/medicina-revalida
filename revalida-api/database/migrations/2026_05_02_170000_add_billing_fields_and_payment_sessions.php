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
            $table->string('current_plan_code')->nullable()->after('monthly_question_limit');
            $table->string('current_plan_name')->nullable()->after('current_plan_code');
            $table->unsignedInteger('current_plan_price_cents')->nullable()->after('current_plan_name');
            $table->timestamp('plan_activated_at')->nullable()->after('current_plan_price_cents');
        });

        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('mockpay');
            $table->string('plan_code');
            $table->string('plan_name');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('status')->default('pending');
            $table->string('reference')->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        DB::table('users')
            ->select('id', 'monthly_question_limit')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $plan = match (true) {
                        (int) $user->monthly_question_limit >= 10000 => [
                            'code' => 'mentoria',
                            'name' => 'Mentoria',
                            'price_cents' => 9900,
                        ],
                        (int) $user->monthly_question_limit >= 5000 => [
                            'code' => 'pro',
                            'name' => 'Pro',
                            'price_cents' => 2900,
                        ],
                        default => [
                            'code' => 'free',
                            'name' => 'Grátis',
                            'price_cents' => 0,
                        ],
                    };

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'current_plan_code' => $plan['code'],
                            'current_plan_name' => $plan['name'],
                            'current_plan_price_cents' => $plan['price_cents'],
                            'plan_activated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_sessions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'current_plan_code',
                'current_plan_name',
                'current_plan_price_cents',
                'plan_activated_at',
            ]);
        });
    }
};
