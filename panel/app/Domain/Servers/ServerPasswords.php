<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;

/**
 * Root password resets and the one-time display of the current password.
 * Passwords never go into the audit log.
 */
final readonly class ServerPasswords
{
    public function __construct(private TaskRunner $tasks) {}

    /**
     * @throws ProvisioningException when another task is still running for the server
     */
    public function requestReset(Server $server, User|ApiToken $actor, ?string $ipAddress): Task
    {
        $task = $this->tasks->start($server, TaskType::ResetPassword, $actor);

        AuditLog::record('server.password_reset', $actor, $server, ['hostname' => $server->hostname], $ipAddress);

        return $task;
    }

    /**
     * The current root password for an administrator. This does not use up
     * the billing system's one fetch of a new password (docs/adr/0007).
     */
    public function reveal(Server $server, User|ApiToken $actor, ?string $ipAddress): ?string
    {
        AuditLog::record('server.password_revealed', $actor, $server, ['hostname' => $server->hostname], $ipAddress);

        return $server->root_password;
    }
}
