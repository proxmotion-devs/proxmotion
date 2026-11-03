<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Domain\Servers\GuestAddresses;
use App\Domain\Servers\GuestScripts;
use App\Enums\AppInstallationStatus;
use App\Enums\AppInstallMode;
use App\Enums\BackupKind;
use App\Enums\GuestScriptEvent;
use App\Enums\OsPlatform;
use App\Enums\PowerAction;
use App\Enums\ServerMoveKind;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\Server;
use App\Models\Task;

/**
 * The steps a task is expected to record, in order, so the server page can
 * list the ones still to come (docs/adr/0032 §5). Steps that depend on the
 * server are listed only when they apply: the hostname script and restart of
 * a server whose VM name differs from its hostname, additional addresses, the
 * template's own script and an app ordered with the server. A pipeline may
 * still record steps that are not listed (they are shown where they happen).
 */
final class TaskPlan
{
    /** @var array<int, list<string>> build steps per server id, worked out once per instance */
    private array $built = [];

    public function __construct(
        private readonly GuestScripts $guestScripts,
        private readonly GuestAddresses $addresses,
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
            TaskType::Terminate => ['stop', 'destroy', ...($task->server->backups()->exists() ? ['delete_backups'] : []), 'release_ips'],
            TaskType::Verify => ['verify'],
            TaskType::ChangeSshKeys => [GuestScriptEvent::SshKeysChanged->step()],
            TaskType::EnableAntiSpoofing, TaskType::ApplyFirewall => ['firewall'],
            TaskType::Move => $this->move($task),
            // Snapshots (docs/adr/0051 §3, §5).
            TaskType::CreateSnapshot => ['snapshot'],
            TaskType::RollbackSnapshot => ['rollback', 'configure', 'resize', 'firewall', 'start', ...$this->refresh()],
            TaskType::DeleteSnapshot => ['delete_snapshot'],
            // Backups (docs/adr/0051 §4, §5).
            TaskType::CreateBackup => ['backup', 'record_backup', ...(($task->input['kind'] ?? null) === BackupKind::Scheduled->value ? ['prune_backups'] : [])],
            TaskType::RestoreBackup => ['stop', 'restore', 'configure', 'resize', 'firewall', 'start', 'wait_agent', ...$this->refresh(), 'verify'],
            TaskType::DeleteBackup => ['delete_backup'],
            default => [],
        };
    }

    /**
     * The stages of MoveServerPipeline that apply to the move (docs/adr/0037 §7).
     *
     * @return list<string>
     */
    private function move(Task $task): array
    {
        $move = $task->move;

        if ($move === null) {
            return [];
        }

        // Before the check knows, an active server is taken to be running.
        $running = $move->was_running ?? $task->server->status === ServerStatus::Active;

        $verify = $running ? ['verify'] : [];

        return match ($move->kind) {
            ServerMoveKind::Node => ['check', 'migrate', 'record', ...$verify],
            ServerMoveKind::Cluster => ['check', 'migrate', 'record', 'adopt', 'firewall', ...$verify],
            ServerMoveKind::Location => [
                'check', ...($running ? ['shutdown'] : []), 'migrate', 'record', 'adopt', 'configure_network', 'firewall',
                ...($running ? ['start', 'wait_agent'] : []), GuestScriptEvent::AddressesChanged->step(), ...$verify,
            ],
        };
    }

    /**
     * The guest scripts GuestRefresh runs after a rollback or restore (docs/adr/0051 §5).
     *
     * @return list<string>
     */
    private function refresh(): array
    {
        return [GuestScriptEvent::AddressesChanged->step(), GuestScriptEvent::HostnameChanged->step(), GuestScriptEvent::SshKeysChanged->step()];
    }

    /**
     * The steps of CreateServerPipeline::BUILD_STAGES and the ordered app.
     *
     * @return list<string>
     */
    private function build(Server $server): array
    {
        return $this->built[$server->id] ??= $this->buildSteps($server);
    }

    /**
     * @return list<string>
     */
    private function buildSteps(Server $server): array
    {
        $steps = ['clone', 'configure', 'resize', 'firewall', 'start', 'wait_agent'];

        if ($server->vm_name !== null && $server->vm_name !== $server->hostname && $this->guestScripts->has($server, GuestScriptEvent::HostnameChanged)) {
            $steps[] = GuestScriptEvent::HostnameChanged->step();

            if ($this->guestScripts->platform($server) === OsPlatform::Windows) {
                $steps[] = PowerAction::Reboot->value;
            }
        }

        // Each event's own script, then the custom scripts bound to it (docs/adr/0043).
        if ($this->addresses->additional($server) !== []) {
            $steps[] = GuestScriptEvent::AddressesChanged->step();
            array_push($steps, ...$this->guestScripts->customSteps($server, GuestScriptEvent::AddressesChanged));
        }

        if ($this->guestScripts->has($server, GuestScriptEvent::Provisioned)) {
            $steps[] = GuestScriptEvent::Provisioned->step();
        }

        array_push($steps, ...$this->guestScripts->customSteps($server, GuestScriptEvent::Provisioned));

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
