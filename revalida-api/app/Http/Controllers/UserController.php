<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        abort_unless((bool) $request->user()?->is_admin, 403, 'Acesso restrito');

        return User::query()
            ->withCount('simulations')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (User $user) => $this->formatUser($user));
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'is_admin' => (bool) $user->is_admin,
            'is_active' => (bool) $user->is_active,
            'email_verified_at' => optional($user->email_verified_at)->toIso8601String(),
            'created_at' => optional($user->created_at)->toIso8601String(),
            'updated_at' => optional($user->updated_at)->toIso8601String(),
            'simulations_count' => (int) ($user->simulations_count ?? 0),
            'monthly_question_limit' => (int) ($user->monthly_question_limit ?? 5000),
        ];
    }
}
