<?php

namespace App\Http\Controllers;

use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    public function overview(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'provider' => (string) config('billing.provider', 'mockpay'),
            'currency' => (string) config('billing.currency', 'BRL'),
            'plans' => $this->serializePlans($user),
            'current_plan' => $this->serializeCurrentPlan($user),
            'current_plans_by_category' => $this->serializeCurrentPlansByCategory($user),
            'active_payment_session' => $this->serializePaymentSession(
                $user->paymentSessions()
                    ->where('status', 'pending')
                    ->latest('id')
                    ->first()
            ),
        ]);
    }

    public function checkout(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'plan_code' => 'required|string',
            'plan_category' => 'nullable|string',
        ]);

        $plan = $this->resolvePlan($data['plan_code']);
        if ($plan === null) {
            throw ValidationException::withMessages([
                'plan_code' => ['Plano inválido'],
            ]);
        }

        $requestCategory = $this->normalizeCategory($data['plan_category'] ?? null);
        $planCategory = $this->normalizeCategory($plan['category'] ?? null);

        if ($requestCategory !== null && $requestCategory !== $planCategory) {
            throw ValidationException::withMessages([
                'plan_category' => ['Categoria do plano é inválida'],
            ]);
        }

        $targetCategory = $planCategory ?? 'revalida';
        $currentPlanCode = $this->resolveCurrentPlanCodeForCategory($user, $targetCategory);

        if ($currentPlanCode === $plan['code']) {
            throw ValidationException::withMessages([
                'plan_code' => ['Esse já é o plano atual da categoria'],
            ]);
        }

        $paymentSession = DB::transaction(function () use ($user, $plan, $targetCategory) {
            $user->paymentSessions()
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);

            $session = $user->paymentSessions()->create([
                'provider' => (string) config('billing.provider', 'mockpay'),
                'plan_code' => $plan['code'],
                'plan_name' => $plan['name'],
                'amount_cents' => $plan['price_cents'],
                'currency' => (string) config('billing.currency', 'BRL'),
                'status' => $plan['price_cents'] > 0 ? 'pending' : 'paid',
                'reference' => $this->buildReference(),
                'paid_at' => $plan['price_cents'] > 0 ? null : now(),
                'meta' => [
                    'mock' => true,
                    'description' => $plan['description'],
                    'plan_category' => $targetCategory,
                ],
            ]);

            if ($plan['price_cents'] === 0) {
                $this->applyPlanToUser($user, $plan, $targetCategory);
            }

            return $session->fresh();
        });

        return response()->json([
            'payment_session' => $this->serializePaymentSession($paymentSession),
            'current_plan' => $this->serializeCurrentPlan($user->fresh()),
            'current_plans_by_category' => $this->serializeCurrentPlansByCategory($user->fresh()),
            'user' => $this->serializeUserBilling($user->fresh()),
            'message' => $plan['price_cents'] > 0
                ? 'Checkout mock criado. Você já pode simular o pagamento.'
                : 'Plano ativado para esta categoria.',
        ], 201);
    }

    public function confirm(Request $request, PaymentSession $paymentSession)
    {
        $user = $request->user();
        abort_unless((int) $paymentSession->user_id === (int) $user->id, 404);

        if ($paymentSession->status !== 'pending') {
            throw ValidationException::withMessages([
                'payment_session' => ['Só sessões pendentes podem ser confirmadas'],
            ]);
        }

        $plan = $this->resolvePlan($paymentSession->plan_code);
        if ($plan === null) {
            throw ValidationException::withMessages([
                'payment_session' => ['Plano da sessão não está mais disponível'],
            ]);
        }

        $planCategory = $this->normalizeCategory($plan['category'] ?? null);
        $targetCategory = $planCategory ?? 'revalida';

        DB::transaction(function () use ($paymentSession, $user, $plan, $targetCategory) {
            $paymentSession->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
            ])->save();

            $this->applyPlanToUser($user, $plan, $targetCategory);
        });

        return response()->json([
            'payment_session' => $this->serializePaymentSession($paymentSession->fresh()),
            'current_plan' => $this->serializeCurrentPlan($user->fresh()),
            'current_plans_by_category' => $this->serializeCurrentPlansByCategory($user->fresh()),
            'user' => $this->serializeUserBilling($user->fresh()),
            'message' => 'Pagamento mock confirmado. Plano da categoria ativado.',
        ]);
    }

    public function cancel(Request $request, PaymentSession $paymentSession)
    {
        $user = $request->user();
        abort_unless((int) $paymentSession->user_id === (int) $user->id, 404);

        if ($paymentSession->status !== 'pending') {
            throw ValidationException::withMessages([
                'payment_session' => ['Só sessões pendentes podem ser canceladas'],
            ]);
        }

        $paymentSession->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ])->save();

        return response()->json([
            'payment_session' => $this->serializePaymentSession($paymentSession->fresh()),
            'current_plan' => $this->serializeCurrentPlan($user->fresh()),
            'current_plans_by_category' => $this->serializeCurrentPlansByCategory($user->fresh()),
            'user' => $this->serializeUserBilling($user->fresh()),
            'message' => 'Checkout mock cancelado.',
        ]);
    }

    private function serializePlans(User $user): array
    {
        $currentByCategory = [
            'revalida' => $this->resolveCurrentPlanCodeForCategory($user, 'revalida'),
            'estudo_geral' => $this->resolveCurrentPlanCodeForCategory($user, 'estudo_geral'),
        ];

        return $this->plans()
            ->map(fn (array $plan) => [
                ...$plan,
                'is_current' => $currentByCategory[(string) $plan['category']] === $plan['code'],
            ])
            ->values()
            ->all();
    }

    private function serializeCurrentPlan(User $user): array
    {
        $revalida = $this->resolveCurrentPlanSummary($user, 'revalida');
        $fallback = $this->resolvePlan((string) ($user->current_plan_code ?? ''));

        $code = $revalida['code'] ?? ($fallback['code'] ?? null);
        $name = $revalida['name'] ?? ($fallback['name'] ?? null);
        $priceCents = $revalida['price_cents'] ?? ($fallback['price_cents'] ?? 0);
        $monthlyLimit = $revalida['monthly_question_limit'] ?? (($fallback['monthly_question_limit'] ?? 0) ?: 1000);

        return [
            'code' => $code,
            'name' => $name,
            'price_cents' => (int) $priceCents,
            'monthly_question_limit' => (int) $monthlyLimit,
            'activated_at' => optional($user->plan_activated_at)->toIso8601String(),
        ];
    }

    private function serializeCurrentPlansByCategory(User $user): array
    {
        return [
            'revalida' => $this->resolveCurrentPlanSummary($user, 'revalida'),
            'estudo_geral' => $this->resolveCurrentPlanSummary($user, 'estudo_geral'),
        ];
    }

    private function serializePaymentSession(?PaymentSession $paymentSession): ?array
    {
        if (!$paymentSession) {
            return null;
        }

        $meta = is_array($paymentSession->meta) ? $paymentSession->meta : [];

        return [
            'id' => (int) $paymentSession->id,
            'provider' => $paymentSession->provider,
            'plan_code' => $paymentSession->plan_code,
            'plan_name' => $paymentSession->plan_name,
            'amount_cents' => (int) $paymentSession->amount_cents,
            'currency' => $paymentSession->currency,
            'status' => $paymentSession->status,
            'reference' => $paymentSession->reference,
            'paid_at' => optional($paymentSession->paid_at)->toIso8601String(),
            'cancelled_at' => optional($paymentSession->cancelled_at)->toIso8601String(),
            'created_at' => optional($paymentSession->created_at)->toIso8601String(),
            'mock_qr_code' => $paymentSession->status === 'pending'
                ? sprintf('mockpay://checkout/%s', $paymentSession->reference)
                : null,
            'plan_category' => (string) ($meta['plan_category'] ?? ''),
        ];
    }

    private function serializeUserBilling(User $user): array
    {
        return [
            'monthly_question_limit' => (int) ($user->monthly_question_limit ?? 5000),
            'current_plan_code' => $user->current_plan_code,
            'current_plan_name' => $user->current_plan_name,
            'current_plan_price_cents' => (int) ($user->current_plan_price_cents ?? 0),
            'current_plans_by_category' => $this->serializeCurrentPlansByCategory($user),
            'plan_activated_at' => optional($user->plan_activated_at)->toIso8601String(),
        ];
    }

    private function applyPlanToUser(User $user, array $plan, string $category): void
    {
        $category = $this->normalizeCategory($category) ?? 'revalida';

        $supportsPlansByCategory = Schema::hasColumn('users', 'current_plans_by_category');
        $plansByCategory = $this->parsePlansByCategory($user->current_plans_by_category);
        $plansByCategory[$category] = [
            'code' => $plan['code'],
            'activated_at' => now()->toIso8601String(),
            'name' => $plan['name'],
            'price_cents' => (int) $plan['price_cents'],
            'monthly_question_limit' => (int) $plan['monthly_question_limit'],
        ];

        $limits = [
            (int) ($user->monthly_question_limit ?? 1000),
            (int) $plan['monthly_question_limit'],
        ];

        if ($supportsPlansByCategory) {
            foreach ($plansByCategory as $entry) {
                $code = $this->extractCodeFromStoredPlan($entry);
                if ($code === null) {
                    continue;
                }
                $resolved = $this->resolvePlan($code);
                if ($resolved === null) {
                    continue;
                }
                $limits[] = (int) $resolved['monthly_question_limit'];
            }
        }

        $revalidaPlan = $this->resolveCurrentPlanSummary($user, 'revalida');

        $payload = [
            'monthly_question_limit' => max($limits),
            'plan_activated_at' => now(),
        ];

        if ($supportsPlansByCategory) {
            $payload['current_plans_by_category'] = $plansByCategory;
        }

        if ($category === 'revalida') {
            $payload = array_merge($payload, [
                'current_plan_code' => $plan['code'],
                'current_plan_name' => $plan['name'],
                'current_plan_price_cents' => (int) $plan['price_cents'],
            ]);
        } elseif (!isset($revalidaPlan['code'])) {
            $fallback = $this->resolvePlan((string) ($user->current_plan_code ?? ''));
            if ($fallback !== null) {
                $payload = array_merge($payload, [
                    'current_plan_code' => $fallback['code'],
                    'current_plan_name' => $fallback['name'],
                    'current_plan_price_cents' => (int) $fallback['price_cents'],
                ]);
            }
        }

        $user->forceFill($payload)->save();
    }

    private function resolvePlan(string $planCode): ?array
    {
        return $this->plans()
            ->first(fn (array $plan) => $plan['code'] === Str::lower(trim($planCode)));
    }

    private function plans(): Collection
    {
        return collect(config('billing.plans', []))
            ->map(function (array $plan) {
                return [
                    'code' => Str::lower((string) ($plan['code'] ?? '')),
                    'name' => (string) ($plan['name'] ?? 'Plano'),
                    'category' => (string) ($plan['category'] ?? 'revalida'),
                    'price_cents' => (int) ($plan['price_cents'] ?? 0),
                    'monthly_question_limit' => (int) ($plan['monthly_question_limit'] ?? 0),
                    'description' => (string) ($plan['description'] ?? ''),
                ];
            })
            ->filter(fn (array $plan) => $plan['code'] !== '')
            ->values();
    }

    private function buildReference(): string
    {
        return 'MOCK-' . strtoupper(Str::random(10));
    }

    private function normalizeCategory(?string $category): ?string
    {
        $normalized = Str::lower(trim((string) $category));

        return match ($normalized) {
            'revalida' => 'revalida',
            'estudo_geral', 'estudo-geral', 'geral' => 'estudo_geral',
            default => null,
        };
    }

    private function parsePlansByCategory(mixed $currentPlansByCategory): array
    {
        $result = [
            'revalida' => null,
            'estudo_geral' => null,
        ];

        if (!is_array($currentPlansByCategory)) {
            return $result;
        }

        foreach (['revalida', 'estudo_geral'] as $category) {
            if (!array_key_exists($category, $currentPlansByCategory)) {
                continue;
            }

            $entry = $currentPlansByCategory[$category];
            if (is_string($entry) && $entry !== '') {
                $result[$category] = ['code' => $entry];
                continue;
            }

            if (is_array($entry)) {
                $result[$category] = $entry;
            }
        }

        return $result;
    }

    private function extractCodeFromStoredPlan(mixed $entry): ?string
    {
        if (is_string($entry) && $entry !== '') {
            return $this->normalizePlanCode($entry);
        }

        if (is_array($entry) && isset($entry['code'])) {
            return $this->normalizePlanCode((string) $entry['code']);
        }

        return null;
    }

    private function normalizePlanCode(string $code): ?string
    {
        $normalized = Str::lower(trim($code));
        return $normalized === '' ? null : $normalized;
    }

    private function resolveCurrentPlanCodeForCategory(User $user, string $category): ?string
    {
        $plansByCategory = $this->parsePlansByCategory($user->current_plans_by_category);
        $entry = $plansByCategory[$this->normalizeCategory($category) ?? 'revalida'] ?? null;
        $code = $this->extractCodeFromStoredPlan($entry);
        if ($code !== null) {
            return $code;
        }

        if ($category === 'revalida') {
            return $this->extractCodeFromStoredPlan((string) ($user->current_plan_code ?? ''));
        }

        return null;
    }

    private function resolveCurrentPlanSummary(User $user, string $category): ?array
    {
        $entry = $this->parsePlansByCategory($user->current_plans_by_category)[$category] ?? null;
        $code = $this->extractCodeFromStoredPlan($entry);

        if ($code === null) {
            if ($category === 'revalida') {
                $code = $this->extractCodeFromStoredPlan((string) ($user->current_plan_code ?? ''));
            }

            if ($code === null) {
                return null;
            }
        }

        $plan = $this->resolvePlan($code);
        if ($plan === null) {
            return null;
        }

        $activatedAt = null;
        if (is_array($entry) && isset($entry['activated_at']) && is_string($entry['activated_at'])) {
            $activatedAt = $entry['activated_at'];
        }

        return [
            'code' => $code,
            'category' => $category,
            'name' => $plan['name'],
            'price_cents' => (int) $plan['price_cents'],
            'monthly_question_limit' => (int) $plan['monthly_question_limit'],
            'description' => (string) ($plan['description'] ?? ''),
            'activated_at' => $activatedAt,
        ];
    }
}
