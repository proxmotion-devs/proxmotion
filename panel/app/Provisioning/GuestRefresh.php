<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Domain\Servers\GuestScriptFailed;
use App\Domain\Servers\GuestScripts;
use App\Enums\GuestScriptEvent;
use App\Models\Server;
use App\Models\Task;

/**
 * Writes into a running guest what the panel keeps there: its addresses,
 * hostname and SSH keys (docs/adr/0051 §5). Needed after a snapshot
 * rollback, which brings back the guest's disk of that time: at once when
 * the server runs, else at its next start through the panel
 * (servers.guest_refresh_pending, read by PowerPipeline).
 */
final readonly class GuestRefresh
{
    public function __construct(private GuestScripts $scripts) {}

    /**
     * The flag is cleared once the address script ran; when the agent did
     * not answer, the next start tries again.
     */
    public function run(Task $task, Server $server, bool $waitForAgent = true): void
    {
        $refreshed = $this->scripts->run($task, $server, GuestScriptEvent::AddressesChanged, waitForAgent: $waitForAgent);
        $this->scripts->run($task, $server, GuestScriptEvent::HostnameChanged);

        try {
            if ($this->scripts->run($task, $server, GuestScriptEvent::SshKeysChanged)) {
                $task->completeStep(GuestScriptEvent::SshKeysChanged->step());
            }
        } catch (GuestScriptFailed $e) {
            // The rest succeeded; the keys can be sent again.
            $task->completeStep(GuestScriptEvent::SshKeysChanged->step(), 'The SSH keys could not be written: '.$e->getMessage());
        }

        if ($refreshed || ! $this->scripts->has($server, GuestScriptEvent::AddressesChanged)) {
            $server->forceFill(['guest_refresh_pending' => false])->save();
        }
    }
}
