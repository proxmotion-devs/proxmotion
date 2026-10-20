<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Domain\Servers\GuestAddresses;
use App\Domain\Servers\GuestScripts;
use App\Enums\AppInstallationStatus;
use App\Enums\AppInstallMode;
use App\Enums\GuestScriptEvent;
use App\Enums\OsPlatform;
use App\Enums\PowerAction;
use App\Enums\TaskType;
use App\Models\Server;
use App\Models\Task;

/**
 * The steps a task is expected to record, in order, so the server page can
 * list the ones still to come (docs/adr/0032 §5). Steps that depend on the
 * server are listed only when they apply: the hostname script and restart of
 * a server named from its location's template, additional addresses, the
 * template's own script and an app ordered with the server. A pipeline may
 * still record steps that are not listed (they are shown where they happen).
 */
final readonly class TaskPlan
{
    public function __construct(
        private GuestScripts $guestScripts,
        private GuestAddresses $addresses,
    ) {}

    /**
     * @return list<string> step keys
     */
    public function steps(Task $task): array
    {
        return match ($task->type) {
            // Rebuilt from scratch (docs/adr/0032 §7): the half-built VM is deleted first.
            TaskType::Create => [...(($task->input['fresh'] ?? false) === true ? ['stop', 'destroy'] : []), ...$this->build($task->server)],
            TaskType::Reinstall => ['stop', 'destroy', ...$this->build($task->server)],
            TaskType::ChangeResources => ['configure', 'resize', 'addresses', 'apply'],
            TaskType::ChangePrimaryAddress, TaskType::ChangeAddresses => ['addresses', 'guest_addresses'],
            TaskType::Unsuspend => ['start', 'wait_agent'],
            TaskType::Terminate => ['stop', 'destroy', 'release_ips'],
            TaskType::Verify => ['verify'],
            TaskType::EnableAntiSpoofing => ['firewall'],
            default => [],
        };
    }

    /**
     * The steps of CreateServerPipeline::BUILD_STAGES and the ordered app.
     *
     * @return list<string>
     */
    private function build(Server $server): array
    {
        $steps = ['clone', 'configure', 'resize', 'firewall', 'start', 'wait_agent'];

        if ($server->vm_name !== null && $server->vm_name !== $server->hostname && $this->guestScripts->has($server, GuestScriptEvent::HostnameChanged)) {
            $steps[] = GuestScriptEvent::HostnameChanged->step();

            if ($this->guestScripts->platform($server) === OsPlatform::Windows) {
                $steps[] = PowerAction::Reboot->value;
            }
        }

        if ($this->addresses->additional($server) !== []) {
            $steps[] = GuestScriptEvent::AddressesChanged->step();
        }

        if ($this->guestScripts->has($server, GuestScriptEvent::Provisioned)) {
            $steps[] = GuestScriptEvent::Provisioned->step();
        }

        $steps[] = 'verify';

        $orderedApp = $server->appInstallations()
            ->where('mode', AppInstallMode::Order)
            ->where('status', AppInstallationStatus::Queued)
            ->exists();

        if ($orderedApp) {
            array_push($steps, 'app_upload', 'app_start');
        }

        return $steps;
    }
}
