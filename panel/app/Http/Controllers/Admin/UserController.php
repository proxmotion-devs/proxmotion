<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateAdministrator;
use App\Domain\Notifications\AdminNotifier;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function store(Request $request, #[CurrentUser] User $admin, CreateAdministrator $creator, AdminNotifier $notifier): RedirectResponse
    {
        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $request->validate(CreateAdministrator::rules());

        $user = $creator->create($validated, $admin, $request->ip());
        $notifier->adminCreated($user, $admin);

        $this->toast('success', 'Administrator :name created.', ['name' => $user->name]);

        return to_route('admin.users.index');
    }
}
