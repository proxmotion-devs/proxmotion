<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Servers\GuestScripts;
use App\Enums\GuestScriptEvent;
use App\Models\Task;
use App\Proxmox\Exceptions\GuestAgentException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Str;

/**
 * Sets a new root (Windows: Administrator) password inside the running VM
 * through the QEMU guest agent, or the OS template's password script: a random one generated here, or the one chosen in the billing
 * system (the task's encrypted secret, cleared afterwards). It is stored
 * (encrypted) only after the agent accepted it. Admins never type one.
 *
 * The cloud-init password is deliberately left alone: changing it would make
 * cloud-init treat the next boot as a new instance and regenerate SSH host keys.
 */
final readonly class ResetPasswordPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private GuestScripts $scripts,
    ) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);

        $chosen = $task->secret;

        try {
            if (! $agent->ping($server->vmid)) {
                throw GuestAgentException::notReachable($server->vmid);
            }

            $password = $chosen ?? Str::password(20, symbols: false);

            // The template's own script gets the password on standard input and
            // throws when it fails (ADR 0010); otherwise the agent sets it.
            if ($this->scripts->has($server, GuestScriptEvent::PasswordReset)) {
                $this->scripts->run($task, $server, GuestScriptEvent::PasswordReset, input: $password."\n");
            } else {
                $agent->setUserPassword($server->vmid, $this->scripts->platform($server)->administrator(), $password);
            }
        } finally {
            // A chosen password is not kept on the task, whether it worked or not.
            if ($chosen !== null) {
                $task->forceFill(['secret' => null])->save();
            }
        }

        // The billing system already knows a password it chose; a random one it may fetch once.
        $server->forceFill(['root_password' => $password, 'root_password_unrevealed' => $chosen === null])->save();
        $task->completeStep('set_password');
    }
}
