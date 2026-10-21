<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Enums\DriftType;
use App\Enums\NodeStatus;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Models\Cluster;
use App\Models\Server;
use App\Models\ServerDrift;
use App\Provisioning\Network\GuestIsolation;
use App\Provisioning\Network\NetworkConfig;
use App\Proxmox\Data\VmResource;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\QemuResource;
use App\Support\Network\Cidr;
use Illuminate\Support\Facades\Log;

/**
 * Compares the VM firewalls of a few servers per sync with what anti-spoofing
 * needs (docs/adr/0033): firewall, IP and MAC filter on, no router
 * advertisements, net0's firewall flag, the DHCP server rules, and an IP set
 * of exactly the server's addresses; and the isolation rules and IP sets
 * (docs/adr/0034). Each server takes a few API calls, so
 * every sync looks at the servers checked longest ago. Nothing is repaired:
 * a difference becomes a drift (ADR 0005) an administrator restores.
 */
final readonly class FirewallChecker
{
    /** Servers looked at per sync of a cluster. */
    public const BATCH = 20;

    public function __construct(
        private ProxmoxClientFactory $clients,
        private NetworkConfig $network,
        private GuestIsolation $isolation,
    ) {}

    /**
     * @param  list<VmResource>  $guests  every guest of the cluster, from /cluster/resources
     */
    public function check(Cluster $cluster, array $guests): FirewallCheck
    {
        $vms = [];

        foreach ($guests as $guest) {
            if ($guest->type === 'qemu' && ! $guest->template) {
                $vms[$guest->vmid] = $guest;
            }
        }

        $servers = Server::query()
            ->where('cluster_id', $cluster->id)
            ->whereIn('status', [ServerStatus::Active, ServerStatus::Suspended])
            ->whereIn('vmid', array_keys($vms))
            ->whereHas('node', fn ($query) => $query->where('status', NodeStatus::Online))
            // A running task changes the firewall on purpose; look again next time.
            ->whereDoesntHave('tasks', fn ($query) => $query->whereIn('status', [TaskStatus::Queued, TaskStatus::Running]))
            ->with('node')
            ->orderByRaw('firewall_checked_at is not null')
            ->orderBy('firewall_checked_at')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        if ($servers->isEmpty()) {
            return new FirewallCheck;
        }

        $client = $this->clients->forCluster($cluster);
        $checked = [];
        $findings = [];

        foreach ($servers as $server) {
            // Moved to another node: NodeMismatch says so; check it once that is settled.
            if ($vms[$server->vmid]->node !== $server->node->name) {
                continue;
            }

            try {
                $problems = $this->problems($client->qemu($server->node->name), $server);
            } catch (ProxmoxException $e) {
                Log::warning('Could not read the VM firewall of a server.', ['server' => $server->uuid, 'error' => $e->getMessage()]);

                continue;
            }

            $server->forceFill(['firewall_checked_at' => now()])->save();
            $checked[] = $server->id;

            if ($problems['problems'] !== []) {
                $findings[] = [DriftType::FirewallMismatch, $server->vmid, $server->id, ['addresses' => $problems['expected']], [
                    'problems' => $problems['problems'],
                    'missing' => $problems['missing'],
                    'extra' => $problems['extra'],
                ]];
            }
        }

        return new FirewallCheck($checked, $findings);
    }

    /**
     * Look at one server's VM firewall at once, after the panel restored it
     * (Enable anti-spoofing, Apply again): its firewall drift closes, or is
     * updated with what is still wrong, without waiting for the next sync.
     *
     * @throws ProxmoxException
     */
    public function recheck(Server $server, QemuResource $qemu): void
    {
        $problems = $this->problems($qemu, $server);
        $server->forceFill(['firewall_checked_at' => now()])->save();

        $drift = ServerDrift::query()
            ->where('server_id', $server->id)
            ->where('type', DriftType::FirewallMismatch)
            ->whereNull('resolved_at')
            ->first();

        if ($problems['problems'] === []) {
            $drift?->resolve('restored');

            return;
        }

        $drift ??= new ServerDrift;
        $drift->forceFill([
            'cluster_id' => $server->cluster_id,
            'server_id' => $server->id,
            'vmid' => $server->vmid,
            'type' => DriftType::FirewallMismatch,
            'expected' => ['addresses' => $problems['expected']],
            'actual' => ['problems' => $problems['problems'], 'missing' => $problems['missing'], 'extra' => $problems['extra']],
            'first_seen_at' => $drift->exists ? $drift->first_seen_at : now(),
            'last_seen_at' => now(),
        ])->save();
    }

    /**
     * @return array{problems: list<string>, expected: list<string>, missing: list<string>, extra: list<string>}
     *
     * @throws ProxmoxException
     */
    private function problems(QemuResource $qemu, Server $server): array
    {
        $options = $qemu->firewallOptions($server->vmid);
        $problems = [];

        // Missing options keep Proxmox's defaults: firewall and IP filter off, MAC filter on, no router advertisements.
        if ((int) ($options['enable'] ?? 0) !== 1) {
            $problems[] = 'firewall_off';
        }

        if ((int) ($options['ipfilter'] ?? 0) !== 1) {
            $problems[] = 'ipfilter_off';
        }

        if ((int) ($options['macfilter'] ?? 1) !== 1) {
            $problems[] = 'macfilter_off';
        }

        if ((int) ($options['radv'] ?? 0) === 1) {
            $problems[] = 'radv_on';
        }

        if (! QemuResource::interfaceFirewalled((string) (($qemu->config($server->vmid) ?? [])['net0'] ?? ''))) {
            $problems[] = 'interface_firewall_off';
        }

        $allRules = $qemu->firewallRules($server->vmid);
        $rules = array_filter($allRules, fn (array $rule) => (int) ($rule['enable'] ?? 0) === 1);
        $comments = array_map(fn (array $rule) => $rule['comment'] ?? null, $rules);

        foreach (QemuResource::DHCP_SERVER_RULES as $rule) {
            if (! in_array($rule['comment'], $comments, true)) {
                $problems[] = 'dhcp_server_allowed';

                break;
            }
        }

        if (! QemuResource::isolationRulesInPlace($allRules)) {
            $problems[] = 'isolation_rules_missing';
        }

        $sets = $qemu->ipsetNames($server->vmid);

        foreach ($this->isolation->sets($server) as $name => $entries) {
            $actualEntries = in_array($name, $sets, true) ? array_map(Cidr::canonical(...), $qemu->ipsetEntries($server->vmid, $name)) : null;

            if ($actualEntries === null || array_diff($entries, $actualEntries) !== [] || array_diff($actualEntries, $entries) !== []) {
                $problems[] = 'isolation_sets_differ';

                break;
            }
        }

        $expected = array_map(self::normalize(...), $this->network->allowedAddresses($server));
        $actual = array_map(self::normalize(...), in_array('ipfilter-net0', $sets, true) ? $qemu->antiSpoofingAddresses($server->vmid) : []);
        $missing = array_values(array_diff($expected, $actual));
        $extra = array_values(array_diff($actual, $expected));

        if ($missing !== [] || $extra !== []) {
            $problems[] = 'addresses_differ';
        }

        return ['problems' => $problems, 'expected' => $expected, 'missing' => $missing, 'extra' => $extra];
    }

    /**
     * One spelling per address, so "2001:db8:0::5" and "2001:db8::5" compare equal.
     */
    private static function normalize(string $address): string
    {
        $packed = @inet_pton($address);

        return $packed === false ? $address : (string) inet_ntop($packed);
    }
}
