<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\Server;
use App\Models\User;

/**
 * Every panel user is an administrator (docs/adr/0007), so these rules are
 * about the server's state, not about who owns it. Customers act through
 * the billing API, which has its own token scopes.
 */
class ServerPolicy
{
    /**
     * Reinstalling wipes the disks; it needs an active server. A failed
     * reinstall is retried with the retry action instead.
     */
    public function reinstall(User $user, Server $server): bool
    {
        return $server->status === ServerStatus::Active;
    }

    /**
     * Rebuilding from scratch deletes a half-built VM and creates the server
     * again (docs/adr/0032 §7): only for a creation that failed, or ended
     * without updating the server.
     */
    public function rebuild(User $user, Server $server): bool
    {
        return ($server->status === ServerStatus::Error || $server->isStranded())
            && $server->tasks()->latest('id')->first()?->type === TaskType::Create;
    }

    /**
     * Reverse DNS of the server's addresses, while it is active.
     */
    public function updateRdns(User $user, Server $server): bool
    {
        return $server->status === ServerStatus::Active;
    }

    /**
     * The console is only available while the server is active, so a
     * suspended server cannot be used through it.
     */
    public function console(User $user, Server $server): bool
    {
        return $server->status === ServerStatus::Active;
    }
}
