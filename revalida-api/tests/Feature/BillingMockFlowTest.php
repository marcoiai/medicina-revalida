<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingMockFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('payment_sessions');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('avatar_url')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('google_id')->nullable();
            $table->unsignedInteger('question_text_size')->default(22);
            $table->unsignedInteger('monthly_question_limit')->default(1000);
            $table->string('current_plan_code')->nullable();
            $table->json('current_plans_by_category')->nullable();
            $table->string('current_plan_name')->nullable();
            $table->unsignedInteger('current_plan_price_cents')->nullable();
            $table->timestamp('plan_activated_at')->nullable();
            $table->rememberToken()->nullable();
            $table->timestamps();
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
    }

    public function test_user_can_create_and_confirm_mock_checkout(): void
    {
        $user = User::create([
            'name' => 'Marco',
            'email' => 'marco@example.com',
            'password' => 'secret-123',
            'is_active' => true,
            'monthly_question_limit' => 1000,
            'current_plan_code' => 'free',
            'current_plan_name' => 'Grátis',
            'current_plan_price_cents' => 0,
            'plan_activated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $checkout = $this->postJson('/api/billing/checkout', [
            'plan_code' => 'pro',
        ]);

        $checkout->assertCreated();
        $checkout->assertJsonPath('payment_session.plan_code', 'pro');
        $checkout->assertJsonPath('payment_session.status', 'pending');

        $sessionId = (int) $checkout->json('payment_session.id');

        $confirm = $this->postJson("/api/billing/payment-sessions/{$sessionId}/confirm");

        $confirm->assertOk();
        $confirm->assertJsonPath('payment_session.status', 'paid');
        $confirm->assertJsonPath('user.current_plan_code', 'pro');
        $confirm->assertJsonPath('user.monthly_question_limit', 5000);
    }

    public function test_free_plan_is_activated_immediately(): void
    {
        $user = User::create([
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'secret-123',
            'is_active' => true,
            'monthly_question_limit' => 5000,
            'current_plan_code' => 'pro',
            'current_plan_name' => 'Pro',
            'current_plan_price_cents' => 2900,
            'plan_activated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $checkout = $this->postJson('/api/billing/checkout', [
            'plan_code' => 'free',
        ]);

        $checkout->assertCreated();
        $checkout->assertJsonPath('payment_session.status', 'paid');
        $checkout->assertJsonPath('user.current_plan_code', 'free');
        $checkout->assertJsonPath('user.monthly_question_limit', 1000);
    }
}
