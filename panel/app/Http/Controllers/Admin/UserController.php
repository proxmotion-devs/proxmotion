<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Notifications\AdminNotifier;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrator accounts. There are no customer accounts: customers live in
 * the billing system (docs/adr/0007).
 */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users/Index', [
            'users' => User::query()
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'two_factor' => $user->hasEnabledTwoFactorAuthentication(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $admin, AdminNotifier $notifier): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $user = new User;
        $user->forceFill([
            ...$validated,
            // Created by an admin: the address doesn't need to be confirmed again.
            'email_verified_at' => now(),
        ])->save();

        AuditLog::record('user.created', $admin, $user, ['email' => $user->email], $request->ip());
        $notifier->adminCreated($user, $admin);

        $this->toast('success', 'Administrator :name created.', ['name' => $user->name]);

        return to_route('admin.users.index');
    }
}
