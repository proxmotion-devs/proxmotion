<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Send admins to the admin panel and show clients their own servers.
     */
    public function __invoke(#[CurrentUser] User $user): Response|RedirectResponse
    {
        if ($user->isAdmin()) {
            return to_route('admin.dashboard');
        }

        return Inertia::render('client/Dashboard', [
            // Only the signed-in customer's servers — never anyone else's.
            'servers' => $user->servers()
                ->occupying()
                ->with(['location:id,name', 'package:id,name', 'osTemplate:id,name', 'ipAddresses:id,server_id,address'])
                ->latest()
                ->paginate(12)
                ->through(fn (Server $server) => [
                    'uuid' => $server->uuid,
                    'hostname' => $server->hostname,
                    'status' => $server->status,
                    'location' => $server->location->name,
                    'package' => $server->package?->name,
                    'os_template' => $server->osTemplate?->name,
                    'addresses' => $server->ipAddresses->pluck('address'),
                ]),
        ]);
    }
}
