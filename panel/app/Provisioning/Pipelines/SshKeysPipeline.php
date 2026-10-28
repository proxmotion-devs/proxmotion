<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Servers\GuestScriptFailed;
use App\Domain\Servers\GuestScripts;
use App\Domain\Servers\ServerSshKeys;
use App\Enums\GuestScriptEvent;
use App\Models\Task;
use App\Support\Ssh\PublicKey;

/**
 * Writes the keys a change_ssh_keys task asks for into the server
 * (docs/adr/0049) through the ssh_keys_changed guest script, which also
 * removes the panel's keys that are no longer wanted. The script is
 * critical: when it fails, so does the task, and the server keeps its list,
 * so the same change can simply be sent again. Proxmox's cloud-init sshkeys
 * stay as they are: changing them would give the server new SSH host keys.
 */
final readonly class SshKeysPipeline
{
    public function __construct(private GuestScripts $scripts) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $wanted = ServerSshKeys::wanted($server, $task);

        if (! $this->scripts->has($server, GuestScriptEvent::SshKeysChanged)) {
            throw new GuestScriptFailed('The server\'s OS template has no script for this event.');
        }

        $removed = ServerSshKeys::without(ServerSshKeys::of($server), $wanted);
        $task->forceFill(['input' => [...($task->input ?? []), 'removed_ssh_keys' => array_map(fn (PublicKey $key) => $key->toString(), $removed)]])->save();

        $this->scripts->run($task, $server, GuestScriptEvent::SshKeysChanged);

        $server->forceFill(['ssh_keys' => PublicKey::implode($wanted)])->save();
        $task->completeStep(GuestScriptEvent::SshKeysChanged->step());

        // The template's custom scripts for this event (docs/adr/0043).
        $this->scripts->runCustom($task, $server, GuestScriptEvent::SshKeysChanged);
    }
}
