<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Networking\ReverseDns\ReverseDns;
use App\Http\Requests\RdnsRequest;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

/**
 * Set or remove the PTR of one of a server's addresses (admin panel).
 */
class RdnsController extends Controller
{
    /**
     * $server is unused here but needed: implicit route model binding only
     * resolves parameters the action declares, and RdnsRequest reads it.
     */
    public function __invoke(RdnsRequest $request, Server $server, #[CurrentUser] User $user, ReverseDns $reverseDns): RedirectResponse
    {
        $reverseDns->update($request->ipAddress(), $request->hostname(), $user, $request->ip());

        $this->toast('success', $request->hostname() === null ? 'Reverse DNS removed.' : 'Reverse DNS saved. It may take a few minutes to appear.');

        return back();
    }
}
