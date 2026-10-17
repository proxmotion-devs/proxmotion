<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Servers\GuestScripts;
use App\Enums\GuestScriptEvent;
use App\Enums\OsPlatform;
use App\Enums\PowerAction;
use App\Models\Task;

/**
 * Renames the system inside the server (recorded by HostnameChanger,
 * docs/adr/0012) through the hostname guest script. Windows applies a new
 * computer name at its next restart: done now with "reboot", else the
 * server is flagged reboot_required. The Proxmox VM name is left alone.
 */
final readonly class HostnamePipeline
{
    public function __construct(
        private GuestScripts $guestScripts,
        private PowerPipeline $power,
    ) {}

    public function run(Task $task): void
    {
        $server = $task->server;

        if (! $this->guestScripts->run($task, $server, GuestScriptEvent::HostnameChanged)
            || $this->guestScripts->platform($server) !== OsPlatform::Windows) {
            return;
        }

        if (($task->input['reboot'] ?? false) === true) {
            $this->power->power($task, PowerAction::Reboot);

            return;
        }

        $server->forceFill(['reboot_required' => true])->save();
        $task->completeStep('apply', 'The new name takes effect when the server is restarted.');
    }
}
