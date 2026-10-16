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
     * The console is only available while the server is active, so a
     * suspended customer cannot use it to keep working on the server.
     */
    public function console(User $user, Server $server): bool
    {
        return $server->status === ServerStatus::Active && $this->view($user, $server);
    }
}
