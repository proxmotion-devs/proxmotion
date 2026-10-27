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
use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\QemuResource;

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

    /** The VM is not on its node in Proxmox: deleted or moved outside the panel. */
    public const string MISSING = 'missing';

    /**
     * "running", "stopped" (or another Proxmox status), or null when Proxmox
     * cannot be asked right now. Only meaningful for active servers.
     */
    public function state(Server $server): ?string
    {
        $state = $this->liveState($server);

        return $state === self::MISSING ? null : $state;
    }

    /**
     * Like state(), but MISSING when Proxmox says the VM does not exist on the
     * server's node, so the admin page can say so (the drift report follows
     * at the next sync, ADR 0005).
     */
    public function liveState(Server $server): ?string
    {
        try {
            // Pages load the cluster with only a few columns; the connection needs all of them.
            return $this->clients->forCluster($server->cluster()->firstOrFail())->qemu($server->node->name)->status($server->vmid)['status'];
        } catch (ProxmoxRequestFailed $e) {
            return QemuResource::isMissingVm($e) ? self::MISSING : null;
        } catch (ProxmoxException) {
            return null;
        }
    }
}
