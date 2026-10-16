<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    /**
     * Admins may see every server, customers only their own that still exist.
     */
    public function view(User $user, Server $server): bool
    {
        return $user->isAdmin()
            || ($server->user_id === $user->id && $server->status !== ServerStatus::Terminated);
    }

    /**
     * Power actions need an active server: a suspended server stays off
     * until it is unsuspended.
     */
    public function power(User $user, Server $server): bool
    {
        return $server->status === ServerStatus::Active && $this->view($user, $server);
    }

    /**
     * Reinstalling wipes the disks; it needs an active server. Admins retry a
     * failed reinstall with the retry action instead.
     */
    public function reinstall(User $user, Server $server): bool
    {
        return $server->status === ServerStatus::Active && $this->view($user, $server);
    }

    /**
     * A new root password is set inside the running VM by the guest agent.
     */
    public function resetPassword(User $user, Server $server): bool
    {
        return $server->status === ServerStatus::Active && $this->view($user, $server);
    }

    /**
     * Admins may look at the root password at any time; the owner once after
     * it was set or reset.
     */
    public function revealPassword(User $user, Server $server): bool
    {
        if ($server->root_password === null || ! $this->view($user, $server)) {
            return false;
        }

        return $user->isAdmin() || $server->root_password_unrevealed;
    }

    /**
     * The console is only available while the server is active, so a
     * suspended customer cannot use it to keep working on the server.
     */
    public function console(User $user, Server $server): bool
    {
        return $server->status === ServerStatus::Active && $this->view($user, $server);
    }
}
