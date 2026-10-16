<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Models\Task;
use App\Proxmox\Exceptions\GuestAgentException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Str;

/**
 * Sets a new random root password inside the running VM through the QEMU
 * guest agent. The password is generated here, never sent by a browser, and
 * stored (encrypted) only after the agent accepted it.
 *
 * The cloud-init password is deliberately left alone: changing it would make
 * cloud-init treat the next boot as a new instance and regenerate SSH host keys.
 */
final readonly class ResetPasswordPipeline
{
    public function __construct(private ProxmoxClientFactory $clients) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);

        if (! $agent->ping($server->vmid)) {
            throw GuestAgentException::notReachable($server->vmid);
        }

        $password = Str::password(20, symbols: false);
        $agent->setUserPassword($server->vmid, 'root', $password);

        $server->forceFill(['root_password' => $password, 'root_password_unrevealed' => true])->save();
        $task->completeStep('set_password');
    }
}
