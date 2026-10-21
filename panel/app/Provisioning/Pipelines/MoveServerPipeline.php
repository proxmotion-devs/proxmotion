<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Infrastructure\FirewallChecker;
use App\Domain\Servers\GuestScripts;
use App\Domain\Servers\ServerVerifier;
use App\Enums\GuestScriptEvent;
use App\Enums\ServerMoveKind;
use App\Enums\ServerMoveStatus;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerMove;
use App\Models\Task;
use App\Provisioning\IpAllocator;
use App\Provisioning\Moves\ServerMover;
use App\Provisioning\Network\NetworkConfig;
use App\Provisioning\Network\ServerFirewall;
use App\Provisioning\Stages\HasOwnDeadline;
use App\Provisioning\Stages\StagedPipeline;
use App\Provisioning\Stages\StageRunner;
use App\Provisioning\VmLabels;
use App\Proxmox\Data\RemoteEndpoint;
use App\Proxmox\Data\Upid;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClient;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves a server to another node, cluster or location (docs/adr/0037 §7).
 * Within a cluster Proxmox's migrate moves the VM; to another cluster
 * remote_migrate copies it with the target's incoming move token and deletes
 * the source once it succeeded. Then the server is recorded at the target,
 * the moved VM is put into the target's resource pool, its copied firewall is
 * written again for the target, and after a move to another location its new
 * addresses are applied before and after it starts.
 *
 * Runs in stages (docs/adr/0032); a stage that does not apply to the kind of
 * move does nothing. Every stage looks at what is done first, so a move whose
 * later stage failed can be finished by a new task for the same move.
 */
final readonly class MoveServerPipeline implements HasOwnDeadline, StagedPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private StageRunner $stages,
        private IpAllocator $ips,
        private NetworkConfig $network,
        private ServerFirewall $firewallRules,
        private FirewallChecker $firewall,
        private GuestScripts $guestScripts,
        private ServerVerifier $verifier,
        private ServerMover $mover,
    ) {}

    public function stages(): array
    {
        return ['check', 'shutdown', 'migrate', 'record', 'adopt', 'configure', 'firewall', 'start', 'wait_agent', 'addresses', 'verify', 'finish'];
    }

    public function deadlineMinutes(): int
    {
        return (int) config('proxmotion.moves.deadline_minutes');
    }

    public function runStage(Task $task, string $stage): ?int
    {
        $move = $task->move ?? throw new RuntimeException('The move of this task is missing.');
        $kind = $move->kind;
        $location = $kind === ServerMoveKind::Location;
        // A stopped server stays stopped; one that ran is started again after a move to another location.
        $running = $move->was_running === true;

        return match ($stage) {
            'check' => $this->check($task, $move),
            'shutdown' => $location && $running ? $this->shutdown($task, $move) : null,
            'migrate' => $this->migrate($task, $move),
            'record' => $this->record($task, $move),
            'adopt' => $kind->crossesClusters() ? $this->adopt($task, $move) : null,
            'configure' => $location ? $this->configure($task, $move) : null,
            'firewall' => $kind->crossesClusters() ? $this->writeFirewall($task, $move) : null,
            'start' => $location && $running ? $this->start($task, $move) : null,
            'wait_agent' => $location && $running ? $this->waitForAgent($task, $move) : null,
            'addresses' => $location ? $this->applyAddresses($task, $move) : null,
            'verify' => $running ? $this->verify($task, $move) : null,
            'finish' => $this->finish($task, $move),
            default => throw new RuntimeException("Unknown stage {$stage}."),
        };
    }

    /**
     * The VM is still on its node and the target VMID still free; whether it
     * runs decides a live move. Nothing to check once Proxmox moved it.
     *
     * @throws ProxmoxException
     */
    private function check(Task $task, ServerMove $move): null
    {
        $move->forceFill(['status' => ServerMoveStatus::Running])->save();

        if ($move->migrated_at !== null) {
            return null;
        }

        $qemu = $this->source($move)->qemu($move->sourceNode->name);

        if ($qemu->config($move->source_vmid) === null) {
            throw new RuntimeException('The VM of the server is no longer on its node.');
        }

        if ($move->kind->crossesClusters() && ! $this->target($move)->cluster()->isVmidFree($move->target_vmid)) {
            throw new RuntimeException("VMID {$move->target_vmid} is taken on the target cluster.");
        }

        $move->forceFill(['was_running' => $qemu->status($move->source_vmid)['status'] === 'running'])->save();
        $task->completeStep('check');

        return null;
    }

    /**
     * A move to another location copies the disks of a stopped VM: a clean
     * shutdown first. A guest that does not stop fails the move, and nothing changed.
     */
    private function shutdown(Task $task, ServerMove $move): ?int
    {
        if ($move->migrated_at !== null) {
            return null;
        }

        $client = $this->source($move);
        $qemu = $client->qemu($move->sourceNode->name);

        $delay = $this->stages->proxmoxTask(
            $task,
            $client,
            fn () => $qemu->status($move->source_vmid)['status'] === 'running' ? $qemu->power($move->source_vmid, 'shutdown', forceStop: false) : null,
            timeoutSeconds: 300,
        );

        if ($delay === null) {
            $task->completeStep('shutdown');
        }

        return $delay;
    }

    /**
     * Proxmox moves the VM. A failed move to another location starts the
     * stopped VM again, so the server is as it was.
     *
     * @throws ProxmoxException
     */
    private function migrate(Task $task, ServerMove $move): ?int
    {
        if ($move->migrated_at !== null) {
            return null;
        }

        $client = $this->source($move);

        if ($task->stageValue('upid') === null) {
            $task->startStep('migrate');
        }

        try {
            $delay = $this->stages->proxmoxTask($task, $client, fn () => $this->startMigration($move), timeoutSeconds: $this->deadlineMinutes() * 60);
        } catch (ProxmoxException $e) {
            if ($move->kind === ServerMoveKind::Location && $move->was_running === true) {
                $this->startAgain($client, $move);
            }

            throw $e;
        }

        if ($delay === null) {
            $move->forceFill(['migrated_at' => now()])->save();
            $task->completeStep('migrate', "VM {$move->target_vmid} on {$move->targetNode->name} ({$move->targetCluster->name}).");
        }

        return $delay;
    }

    private function startMigration(ServerMove $move): Upid
    {
        $qemu = $this->source($move)->qemu($move->sourceNode->name);
        $online = $move->was_running === true && $move->kind !== ServerMoveKind::Location;
        $bwlimit = config('proxmotion.moves.bwlimit_kib');
        $bwlimit = is_int($bwlimit) ? $bwlimit : null;
        $storage = $move->targetStorage ?? throw new RuntimeException('The target storage of the move is missing.');

        if ($move->kind === ServerMoveKind::Node) {
            // A disk on shared storage the target node has too stays where it is.
            $keeps = $storage->is_shared && $storage->name === $move->sourceStorage?->name;

            return $qemu->migrate($move->source_vmid, $move->targetNode->name, $online, $keeps ? null : $storage->name, withLocalDisks: ! $keeps, bwlimitKib: $bwlimit);
        }

        $cluster = $move->targetCluster;
        $endpoint = new RemoteEndpoint(
            host: $move->targetNode->apiHost() ?? throw new RuntimeException('The address of the target node is not known.'),
            port: $cluster->port,
            tokenId: (string) $cluster->incoming_token_id,
            tokenSecret: (string) $cluster->incoming_token_secret,
            fingerprint: $cluster->tls_fingerprint,
        );

        return $qemu->remoteMigrate(
            vmid: $move->source_vmid,
            endpoint: $endpoint,
            targetVmid: $move->target_vmid,
            targetStorage: $storage->name,
            targetBridge: $this->targetBridge($move),
            online: $online,
            // A kept source copy stays locked, and only root can unlock or delete it (docs/adr/0037 §7).
            delete: true,
            bwlimitKib: $bwlimit,
        );
    }

    /**
     * The bridge net0 goes to: the same one within the location, the new
     * primary pool's in another location.
     */
    private function targetBridge(ServerMove $move): string
    {
        $address = $move->kind === ServerMoveKind::Location
            ? $move->reservedAddresses()->with('pool')->first()
            : $move->server->ipAddresses()->with('pool')->first();

        return $address->pool->bridge ?? 'vmbr0';
    }

    private function startAgain(ProxmoxClient $client, ServerMove $move): void
    {
        try {
            $qemu = $client->qemu($move->sourceNode->name);

            if ($qemu->config($move->source_vmid) !== null && $qemu->status($move->source_vmid)['status'] !== 'running') {
                $client->taskWaiter()->wait($qemu->power($move->source_vmid, 'start'), timeoutSeconds: 120);
            }
        } catch (ProxmoxException $e) {
            report($e);
        }
    }

    /**
     * The server is where Proxmox put it: its cluster, node, storage, VMID
     * and, for another location, its location and the addresses the move
     * held (the old ones are released).
     *
     * @throws ProxmoxException
     */
    private function record(Task $task, ServerMove $move): null
    {
        if ($move->recorded_at !== null) {
            return null;
        }

        $server = $move->server;

        if ($move->kind === ServerMoveKind::Node) {
            $node = collect($this->source($move)->cluster()->guests())->firstWhere('vmid', $move->source_vmid)?->node;

            if ($node !== $move->targetNode->name) {
                throw new RuntimeException("Proxmox reports the VM on {$node} instead of {$move->targetNode->name}.");
            }
        }

        Cache::lock("provisioning:location:{$move->target_location_id}", 30)->block(15, function () use ($move, $server) {
            DB::transaction(function () use ($move, $server) {
                $server->forceFill([
                    'location_id' => $move->target_location_id,
                    'cluster_id' => $move->target_cluster_id,
                    'node_id' => $move->target_node_id,
                    'storage_id' => $move->target_storage_id,
                    'vmid' => $move->target_vmid,
                    // Looked at again on the next sync of its new cluster.
                    'firewall_checked_at' => null,
                ])->save();

                if ($move->kind === ServerMoveKind::Location) {
                    $this->ips->switchToMoveAddresses($move, $server);
                }

                $move->forceFill(['recorded_at' => now()])->save();
            });
        });

        $task->setRelation('server', $server->refresh());
        $task->completeStep('record');

        return null;
    }

    /**
     * Remote migration puts the VM into no pool: the incoming move token adds
     * it to the target's resource pool, where the panel's token reaches it.
     */
    private function adopt(Task $task, ServerMove $move): null
    {
        $pool = $move->targetCluster->resource_pool;
        $connection = $move->targetCluster->incomingConnection();

        if ($pool !== null && $connection !== null) {
            $this->clients->make($connection)->pools()->addVm($pool, $move->target_vmid);
        }

        $task->completeStep('adopt', $pool === null ? null : "Resource pool {$pool}.");

        return null;
    }

    /**
     * After a move to another location: net0 on the new pool's bridge and
     * VLAN, its nameservers, and the VM's notes and tags with the new
     * addresses. ipconfig0 stays (docs/adr/0011); the address script applies
     * the new addresses inside the guest.
     */
    private function configure(Task $task, ServerMove $move): null
    {
        $server = $this->server($move);
        $qemu = $this->target($move)->qemu($move->targetNode->name);
        $current = $qemu->config($server->vmid) ?? [];
        $params = $this->network->proxmoxParams($server, is_string($current['net0'] ?? null) ? $current['net0'] : null);

        $qemu->updateConfig($server->vmid, array_filter([
            'net0' => $params['net0'] ?? null,
            'nameserver' => $params['nameserver'] ?? null,
            'description' => VmLabels::description($server),
        ], fn ($value) => $value !== null));

        $task->completeStep('configure_network', VmLabels::applyTags($qemu, $server));

        return null;
    }

    /**
     * The VM firewall came along as it was: anti-spoofing, the panel's rules
     * and IP sets are written again for the target (protected networks of
     * the new nodes, new addresses), then read back.
     *
     * @throws ProxmoxException
     */
    private function writeFirewall(Task $task, ServerMove $move): null
    {
        $server = $this->server($move);
        $qemu = $this->target($move)->qemu($move->targetNode->name);
        $addresses = $this->network->allowedAddresses($server);

        $qemu->enableAntiSpoofing($server->vmid, $addresses);
        $qemu->removeFromAntiSpoofing($server->vmid, array_values(array_diff($qemu->antiSpoofingAddresses($server->vmid), $addresses)));
        $qemu->enableInterfaceFirewall($server->vmid);
        $this->firewallRules->apply($qemu, $server, always: true);
        $this->firewall->recheck($server, $qemu);
        $task->completeStep('firewall');

        return null;
    }

    private function start(Task $task, ServerMove $move): ?int
    {
        $client = $this->target($move);
        $qemu = $client->qemu($move->targetNode->name);

        $delay = $this->stages->proxmoxTask(
            $task,
            $client,
            fn () => $qemu->status($move->target_vmid)['status'] !== 'running' ? $qemu->power($move->target_vmid, 'start') : null,
            timeoutSeconds: 300,
        );

        if ($delay === null) {
            $task->completeStep('start');
        }

        return $delay;
    }

    private function waitForAgent(Task $task, ServerMove $move): ?int
    {
        $state = $task->stage_state ?? [];
        $delay = $this->guestScripts->checkReady($task, $this->server($move), $state);
        $task->rememberForStage($state);

        return $delay;
    }

    /**
     * The new primary, default route and additional addresses inside the
     * guest. A stopped server gets them with Apply again once it runs.
     */
    private function applyAddresses(Task $task, ServerMove $move): null
    {
        if ($move->was_running !== true) {
            $task->completeStep(GuestScriptEvent::AddressesChanged->step(), 'The server is stopped: start it, then use Apply again under IP addresses.');

            return null;
        }

        $this->guestScripts->run($task, $this->server($move), GuestScriptEvent::AddressesChanged);

        return null;
    }

    private function verify(Task $task, ServerMove $move): null
    {
        $this->verifier->verifyStep($task, $this->server($move));

        return null;
    }

    private function finish(Task $task, ServerMove $move): null
    {
        $move->forceFill(['status' => ServerMoveStatus::Succeeded, 'error' => null, 'finished_at' => now()])->save();
        AuditLog::record('server.moved', $move->user, $move->server, $this->mover->auditProperties($move));

        return null;
    }

    private function source(ServerMove $move): ProxmoxClient
    {
        return $this->clients->forCluster($move->sourceCluster);
    }

    private function target(ServerMove $move): ProxmoxClient
    {
        return $this->clients->forCluster($move->targetCluster);
    }

    /**
     * The server as it is now, with its relations at the target.
     */
    private function server(ServerMove $move): Server
    {
        return $move->server->refresh();
    }
}
