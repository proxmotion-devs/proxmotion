<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\PowerAction;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Power actions and the VM's current power state, for admins and customers.
 */
final readonly class ServerPower
{
    public function __construct(
        private TaskRunner $tasks,
        private ProxmoxClientFactory $clients,
    ) {}

    /**
     * Queue a power action and record who asked for it.
     *
     * @throws ProvisioningException when another task is still running for the server
     */
    public function request(Server $server, PowerAction $action, User|ApiToken $actor, ?string $ipAddress): Task
    {
        $task = $this->tasks->start($server, $action->taskType(), $actor);

        AuditLog::record('server.power', $actor, $server, ['action' => $action->value, 'hostname' => $server->hostname], $ipAddress);

        return $task;
    }

    /**
     * "running", "stopped" (or another Proxmox status), or null when Proxmox
     * cannot be asked right now. Only meaningful for active servers.
     */
    public function state(Server $server): ?string
    {
        try {
            // Pages load the cluster with only a few columns; the connection needs all of them.
            return $this->clients->forCluster($server->cluster()->firstOrFail())->qemu($server->node->name)->status($server->vmid)['status'];
        } catch (ProxmoxException) {
            return null;
        }
    }
}
