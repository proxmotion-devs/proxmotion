<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RendersConsole;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * The console page of the admin panel. Customers reach the console through a
 * signed single-use link from the billing API (CustomerConsoleController).
 */
class ConsoleController extends Controller
{
    use RendersConsole;

    public function __invoke(Request $request, Server $server, #[CurrentUser] User $user): Response
    {
        Gate::authorize('console', $server);

        return $this->consolePage($request, $server, $user, route('admin.servers.show', $server));
    }
}
