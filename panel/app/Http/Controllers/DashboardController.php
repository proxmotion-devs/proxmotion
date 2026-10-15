<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Send admins to the admin panel and show clients their own dashboard.
     */
    public function __invoke(#[CurrentUser] User $user): Response|RedirectResponse
    {
        if ($user->isAdmin()) {
            return to_route('admin.dashboard');
        }

        return Inertia::render('client/Dashboard');
    }
}
