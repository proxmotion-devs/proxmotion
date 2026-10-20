<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Apps\AppStarter;
use App\Domain\Servers\GuestScripts;
use App\Domain\Servers\ServerVerifier;
use App\Enums\CloneMode;
use App\Enums\GuestScriptEvent;
use App\Enums\OsPlatform;
use App\Enums\PowerAction;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\LinkedClones;
use App\Provisioning\Network\NetworkConfig;
use App\Provisioning\Stages\StagedPipeline;
use App\Provisioning\Stages\StageRunner;
use App\Proxmox\Data\BootDisk;
use App\Proxmox\Data\Upid;
use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\ProxmoxClientFactory;
use RuntimeException;

/**
 * Clone → configure → grow disk → anti-spoofing → start → wait for the guest
 * agent → name the guest → guest scripts → checks from inside → active →
 * ordered app.
 *
 * Runs in stages (docs/adr/0032): waits for Proxmox tasks and the guest end
 * a run, and the next run continues there. Every stage checks Proxmox's actual
 * state first, so running the pipeline again after a failure continues where
 * it stopped instead of starting over.
 */
final readonly class CreateServerPipeline implements StagedPipeline
{
    /** The stages that build and set up a server; reinstalling runs them too. */
    public const BUILD_STAGES = ['clone', 'configure', 'resize', 'firewall', 'start', 'wait_agent', 'name_guest', 'name_guest_wait', 'guest_scripts', 'verify'];

    public function __construct(
        private ProxmoxClientFactory $clients,
        private NetworkConfig $network,
        private GuestScripts $guestScripts,
        private AppStarter $apps,
        private LinkedClones $linkedClones,
        private PowerPipeline $power,
        private StageRunner $stages,
        private ServerVerifier $verifier,
    ) {}

    public function stages(): array
    {
        return ['provision', ...self::BUILD_STAGES, 'finish'];
    }

    public function runStage(Task $task, string $stage): ?int
    {
        $server = $task->server;

        return match ($stage) {
            'provision' => $this->provision($server),
            'finish' => $this->finish($task, $server),
            default => $this->runBuildStage($task, $stage),
        };
    }

    private function provision(Server $server): null
    {
        $server->forceFill(['status' => ServerStatus::Provisioning])->save();

        return null;
    }

    private function finish(Task $task, Server $server): null
    {
        $server->forceFill(['status' => ServerStatus::Active, 'provisioned_at' => now()])->save();

        // The app it was ordered with (ADR 0004): never fails the server.
        $this->apps->startOrdered($task, $server);

        return null;
    }

    /**
     * One of BUILD_STAGES. A reinstall keeps the MAC address of the VM it
     * deleted through the task input previous_net0.
     *
     * @return int|null null once the stage is done, else the seconds until it should run again
     */
    public function runBuildStage(Task $task, string $stage): ?int
    {
        $server = $task->server;

        return match ($stage) {
            'clone' => $this->cloneVm($task, $server),
            'configure' => $this->configure($task, $server),
            'resize' => $this->growDisk($task, $server),
            'firewall' => $this->firewall($task, $server),
            'start' => $this->start($task, $server),
            // Active only once the new system's guest agent answers, and on Windows once
            // setup has finished, on Linux once cloud-init has (or the wait times out, ADR 0004).
            'wait_agent' => $this->waitForAgent($task, $server, setup: true, step: 'wait_agent'),
            // A server named from its location's template: its hostname inside the guest (ADR 0031).
            'name_guest' => $this->nameGuest($task, $server),
            'name_guest_wait' => ($task->input['renamed_with_restart'] ?? false) === true
                ? $this->waitForAgent($task, $server, setup: false, step: null)
                : null,
            // Additional addresses and the template's script (ADR 0009, 0010).
            'guest_scripts' => $this->afterInstall($task, $server),
            // Addresses, gateways, DNS, hostname and disk from inside: a failed check only warns (docs/adr/0032 §6).
            'verify' => $this->verify($task, $server),
            default => throw new RuntimeException("Unknown stage {$stage}."),
        };
    }

    private function afterInstall(Task $task, Server $server): null
    {
        $this->guestScripts->afterInstall($task, $server);

        return null;
    }

    private function verify(Task $task, Server $server): null
    {
        $this->verifier->verifyStep($task, $server);

        return null;
    }

    private function cloneVm(Task $task, Server $server): ?int
    {
        $client = $this->clients->forCluster($server->cluster);

        if ($task->stageValue('upid') === null && $client->qemu($server->node->name)->config($server->vmid) !== null) {
            $task->completeStep('clone', 'VM already exists.');

            return null;
        }

        $delay = $this->stages->proxmoxTask($task, $client, fn () => $this->startClone($task, $server), timeoutSeconds: 1800);

        if ($delay === null) {
            $message = $task->stageValue('message');
            $task->completeStep('clone', is_string($message) ? $message : null);
        }

        return $delay;
    }

    /**
     * Linked clones when the template asks for them and the storage allows it,
     * otherwise a full clone with the reason on the step (docs/adr/0027).
     */
    private function startClone(Task $task, Server $server): Upid
    {
        $client = $this->clients->forCluster($server->cluster);
        $template = $server->osTemplate?->proxmoxTemplates()
            ->where('cluster_id', $server->cluster_id)
            ->orderByRaw('node_id = ? desc', [$server->node_id])
            ->with('node')
            ->first() ?? throw new RuntimeException('The OS template has no Proxmox template on this cluster.');

        $source = $client->qemu($template->node->name);
        $clone = fn (bool $full) => $source->clone(
            templateVmid: $template->vmid,
            newVmid: $server->vmid,
            name: $server->vmName(),
            storage: $server->storage?->name,
            pool: $server->cluster->resource_pool,
            targetNode: $server->node->name,
            full: $full,
        );

        $message = null;
        $upid = null;

        if ($server->osTemplate->clone_mode === CloneMode::Linked) {
            $reason = $this->linkedClones->unavailableReason($server, $template, $source->config($template->vmid) ?? []);

            if ($reason === null) {
                try {
                    $upid = $clone(false);
                    $message = "Linked clone of template {$template->vmid}.";
                } catch (ProxmoxRequestFailed $e) {
                    $reason = "Proxmox refused a linked clone: {$e->getMessage()}";
                }
            }

            $message ??= "Full clone: {$reason}";
        }

        $upid ??= $clone(true);
        $task->rememberForStage(['message' => $message]);

        return $upid;
    }

    private function configure(Task $task, Server $server): null
    {
        $qemu = $this->clients->forCluster($server->cluster)->qemu($server->node->name);
        $current = $qemu->config($server->vmid) ?? [];
        $previousNet0 = is_string($task->input['previous_net0'] ?? null) ? $task->input['previous_net0'] : null;
        $net0 = $previousNet0 ?? (is_string($current['net0'] ?? null) ? $current['net0'] : null);

        $params = [
            'name' => $server->vmName(),
            'description' => "Managed by Proxmotion. Server {$server->uuid}",
            'cores' => $server->cpu_cores,
            'sockets' => 1,
            'memory' => $server->memory_mib,
            'onboot' => 1,
            'agent' => 'enabled=1',
            'ciuser' => 'root',
            'ciupgrade' => 0,
            ...$this->network->proxmoxParams($server, $net0),
        ];

        if ($server->root_password !== null) {
            $params['cipassword'] = $server->root_password;
        }

        if ($server->ssh_keys !== null && trim($server->ssh_keys) !== '') {
            // Proxmox expects the keys URL-encoded inside the (form-encoded) request.
            $params['sshkeys'] = rawurlencode(trim($server->ssh_keys));
        }

        $qemu->updateConfig($server->vmid, $params);

        // Proxmox generates the MAC address on clone; record the one the VM really has.
        $config = $qemu->config($server->vmid) ?? [];
        $server->forceFill(['mac_address' => $this->network->macAddress($config)])->save();
        $task->completeStep('configure');

        return null;
    }

    private function growDisk(Task $task, Server $server): ?int
    {
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);

        $delay = $this->stages->proxmoxTask($task, $client, function () use ($server, $qemu): ?Upid {
            $disk = BootDisk::fromConfig($qemu->config($server->vmid) ?? []) ?? throw new RuntimeException('Could not find the boot disk of the VM.');

            return $disk->sizeMib < $server->disk_mib ? $qemu->resize($server->vmid, $disk->key, $server->disk_mib) : null;
        }, timeoutSeconds: 600);

        if ($delay === null) {
            $task->completeStep('resize');
        }

        return $delay;
    }

    private function firewall(Task $task, Server $server): null
    {
        $qemu = $this->clients->forCluster($server->cluster)->qemu($server->node->name);
        $qemu->enableAntiSpoofing($server->vmid, $this->network->allowedAddresses($server));
        $task->completeStep('firewall');

        return null;
    }

    private function start(Task $task, Server $server): ?int
    {
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);

        $delay = $this->stages->proxmoxTask(
            $task,
            $client,
            fn () => $qemu->status($server->vmid)['status'] !== 'running' ? $qemu->power($server->vmid, 'start') : null,
            timeoutSeconds: 300,
        );

        if ($delay === null) {
            $task->completeStep('start');
        }

        return $delay;
    }

    /**
     * One check of the guest agent (GuestScripts::checkReady()); what the
     * wait remembers is kept on the task between runs.
     */
    private function waitForAgent(Task $task, Server $server, bool $setup, ?string $step): ?int
    {
        $state = $task->stage_state ?? [];
        $delay = $this->guestScripts->checkReady($task, $server, $state, $setup, step: $step);
        $task->rememberForStage($state);

        return $delay;
    }

    /**
     * A server named from its location's template has its address as VM name
     * (docs/adr/0031), from which cloud-init and Cloudbase-Init took the
     * hostname inside the guest: the hostname script sets the real one. Windows
     * applies it at its next restart, done here; the next stage waits for its
     * agent again. A failing script leaves a note.
     */
    private function nameGuest(Task $task, Server $server): null
    {
        $task->rememberInput('renamed_with_restart', null);

        if ($server->vm_name === null || $server->vm_name === $server->hostname) {
            return null;
        }

        if (! $this->guestScripts->run($task, $server, GuestScriptEvent::HostnameChanged)
            || $this->guestScripts->platform($server) !== OsPlatform::Windows) {
            return null;
        }

        $this->power->power($task, PowerAction::Reboot);
        $task->rememberInput('renamed_with_restart', true);

        return null;
    }
}
