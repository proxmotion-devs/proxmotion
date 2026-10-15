<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer accounts. Until the billing API (Phase 4) exists, admins create them here.
 */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users/Index', [
            'users' => User::query()
                ->withCount(['servers' => fn ($query) => $query->occupying()])
                ->orderBy('role')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'two_factor' => $user->hasEnabledTwoFactorAuthentication(),
                    'servers_count' => $user->getAttribute('servers_count'),
                    'created_at' => $user->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $user = new User;
        $user->forceFill([
            ...$validated,
            'role' => UserRole::Client,
            // Created by an admin: the address doesn't need to be confirmed again.
            'email_verified_at' => now(),
        ])->save();

        $this->toast('success', 'Customer :name created.', ['name' => $user->name]);

        return to_route('admin.users.index');
    }
}
