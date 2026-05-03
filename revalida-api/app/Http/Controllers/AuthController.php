<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $email = Str::lower($data['email']);

        $user = User::create([
            'name' => $data['name'],
            'email' => $email,
            'password' => $data['password'],
            'is_active' => true,
            'is_admin' => false,
            'monthly_question_limit' => 1000,
            'current_plans_by_category' => [
                'revalida' => [
                    'code' => 'free',
                    'activated_at' => now()->toIso8601String(),
                ],
                'estudo_geral' => [
                    'code' => 'free_geral',
                    'activated_at' => now()->toIso8601String(),
                ],
            ],
            'current_plan_code' => 'free',
            'current_plan_name' => 'Grátis',
            'current_plan_price_cents' => 0,
            'plan_activated_at' => now(),
        ]);

        return response()->json($this->tokenPayload($user), 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'nullable|email',
            'username' => 'nullable|string',
            'password' => 'required|string',
        ]);

        $email = Str::lower((string) ($data['email'] ?? $data['username'] ?? ''));

        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => ['Email é obrigatório'],
            ]);
        }

        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Conta inativa'],
            ]);
        }

        return response()->json($this->tokenPayload($user));
    }

    public function me(Request $request)
    {
        return response()->json($this->serializeUser($request->user()));
    }

    public function updateMe(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'question_text_size' => 'nullable|integer|min:18|max:28',
        ]);

        if (array_key_exists('question_text_size', $data) && $data['question_text_size'] !== null) {
            $user->question_text_size = (int) $data['question_text_size'];
            $user->save();
        }

        return response()->json($this->serializeUser($user));
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logout realizado']);
    }

    public function googleUrl()
    {
        $this->assertGoogleConfig();

        $state = $this->buildGoogleState();
        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'select_account',
        ]);

        return response()->json([
            'authorization_url' => "https://accounts.google.com/o/oauth2/v2/auth?{$query}",
        ]);
    }

    public function googleCallback(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        $this->assertGoogleConfig();

        if (!$this->isValidGoogleState($data['state'])) {
            throw ValidationException::withMessages([
                'state' => ['Estado OAuth inválido ou expirado'],
            ]);
        }

        $tokenResponse = Http::asForm()
            ->acceptJson()
            ->post('https://oauth2.googleapis.com/token', [
                'code' => $data['code'],
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => config('services.google.redirect'),
                'grant_type' => 'authorization_code',
            ]);

        if (!$tokenResponse->successful()) {
            $googleError = (string) ($tokenResponse->json('error') ?? '');
            $googleErrorDescription = (string) ($tokenResponse->json('error_description') ?? '');
            $message = 'Falha ao trocar o código do Google';

            if ($googleErrorDescription !== '') {
                $message .= ': '.$googleErrorDescription;
            } elseif ($googleError !== '') {
                $message .= ': '.$googleError;
            }

            return response()->json([
                'message' => $message,
                'google_error' => $tokenResponse->json(),
            ], 422);
        }

        $accessToken = (string) $tokenResponse->json('access_token');

        if ($accessToken === '') {
            return response()->json([
                'message' => 'Google não retornou access token',
            ], 422);
        }

        $userInfoResponse = Http::withToken($accessToken)
            ->acceptJson()
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        if (!$userInfoResponse->successful()) {
            $googleError = (string) ($userInfoResponse->json('error') ?? '');
            $googleErrorDescription = (string) ($userInfoResponse->json('error_description') ?? '');
            $message = 'Falha ao buscar dados do usuário no Google';

            if ($googleErrorDescription !== '') {
                $message .= ': '.$googleErrorDescription;
            } elseif ($googleError !== '') {
                $message .= ': '.$googleError;
            }

            return response()->json([
                'message' => $message,
                'google_error' => $userInfoResponse->json(),
            ], 422);
        }

        $googleUser = $userInfoResponse->json();
        $googleId = (string) ($googleUser['sub'] ?? '');
        $email = Str::lower((string) ($googleUser['email'] ?? ''));
        $name = (string) ($googleUser['name'] ?? '');
        $avatarUrl = (string) ($googleUser['picture'] ?? '');
        $emailVerified = (bool) ($googleUser['email_verified'] ?? false);

        if ($googleId === '' || $email === '') {
            return response()->json([
                'message' => 'Dados essenciais não retornados pelo Google',
            ], 422);
        }

        $googleAccount = User::where('google_id', $googleId)->first();
        $emailAccount = User::where('email', $email)->first();

        if ($googleAccount && $emailAccount && $googleAccount->id !== $emailAccount->id) {
            return response()->json([
                'message' => 'Conflito de conta: email já usado por outro usuário',
            ], 409);
        }

        $user = $googleAccount ?? $emailAccount;

        if (!$user) {
            $user = User::create([
                'name' => $name !== '' ? $name : 'Usuário Google',
                'email' => $email,
                'password' => Str::random(40),
                'google_id' => $googleId,
                'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
                'is_active' => true,
                'is_admin' => false,
                'email_verified_at' => $emailVerified ? now() : null,
                'monthly_question_limit' => 1000,
                'current_plans_by_category' => [
                    'revalida' => [
                        'code' => 'free',
                        'activated_at' => now()->toIso8601String(),
                    ],
                    'estudo_geral' => [
                        'code' => 'free_geral',
                        'activated_at' => now()->toIso8601String(),
                    ],
                ],
                'current_plan_code' => 'free',
                'current_plan_name' => 'Grátis',
                'current_plan_price_cents' => 0,
                'plan_activated_at' => now(),
            ]);
        } else {
            $user->fill([
                'google_id' => $googleId,
                'avatar_url' => $avatarUrl !== '' ? $avatarUrl : $user->avatar_url,
                'name' => $user->name ?: ($name !== '' ? $name : $user->name),
                'email_verified_at' => $emailVerified ? ($user->email_verified_at ?: now()) : $user->email_verified_at,
            ]);
            $user->save();
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Conta inativa',
            ], 403);
        }

        return response()->json($this->tokenPayload($user));
    }

    private function tokenPayload(User $user): array
    {
        $token = $user->createToken('auth-token')->plainTextToken;

        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => $this->serializeUser($user),
        ];
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'avatar_url' => $user->avatar_url,
            'is_admin' => (bool) $user->is_admin,
            'is_active' => (bool) $user->is_active,
            'question_text_size' => (int) ($user->question_text_size ?? 22),
            'monthly_question_limit' => (int) ($user->monthly_question_limit ?? 5000),
            'current_plans_by_category' => $user->current_plans_by_category,
            'current_plan_code' => $user->current_plan_code,
            'current_plan_name' => $user->current_plan_name,
            'current_plan_price_cents' => (int) ($user->current_plan_price_cents ?? 0),
            'plan_activated_at' => optional($user->plan_activated_at)->toIso8601String(),
            'ai_enabled' => (bool) config('features.ai_enabled', true),
        ];
    }

    private function assertGoogleConfig(): void
    {
        if (!config('services.google.client_id') || !config('services.google.client_secret') || !config('services.google.redirect')) {
            abort(500, 'Google OAuth não configurado no backend');
        }
    }

    private function buildGoogleState(): string
    {
        $nonce = Str::random(40);
        $issuedAt = (string) now()->timestamp;
        $payload = "{$nonce}|{$issuedAt}";
        $signature = hash_hmac('sha256', $payload, $this->googleStateKey());

        return "{$payload}|{$signature}";
    }

    private function isValidGoogleState(string $state): bool
    {
        $parts = explode('|', $state);

        if (count($parts) !== 3) {
            return false;
        }

        [$nonce, $issuedAt, $signature] = $parts;
        $payload = "{$nonce}|{$issuedAt}";
        $expectedSignature = hash_hmac('sha256', $payload, $this->googleStateKey());

        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        if (!ctype_digit($issuedAt)) {
            return false;
        }

        return ((int) $issuedAt) >= (now()->subMinutes(10)->timestamp);
    }

    private function googleStateKey(): string
    {
        $appKey = (string) config('app.key');

        if (Str::startsWith($appKey, 'base64:')) {
            $decoded = base64_decode(Str::after($appKey, 'base64:'), true);

            return $decoded !== false ? $decoded : $appKey;
        }

        return $appKey;
    }
}
