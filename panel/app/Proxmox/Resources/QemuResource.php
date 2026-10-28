<?php

declare(strict_types=1);

namespace App\Proxmox\Resources;

use App\Proxmox\Data\MigrationPreconditions;
use App\Proxmox\Data\RemoteEndpoint;
use App\Proxmox\Data\RrdPoint;
use App\Proxmox\Data\Upid;
use App\Proxmox\Data\VmStats;
use App\Proxmox\Data\VncProxyTicket;
use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\ProxmoxClient;
use App\Support\Network\Cidr;

/**
 * QEMU/KVM guests on one node.
 */
final readonly class QemuResource
{
    public function __construct(
        private ProxmoxClient $client,
        private string $node,
    ) {}

    /**
     * Clone a template into a new VM: a full copy of its disks, or with
     * `full: false` a linked clone on the template's own storage (Proxmox
     * takes `storage` for full clones only, docs/adr/0027).
     */
    public function clone(int $templateVmid, int $newVmid, string $name, ?string $storage, ?string $pool, ?string $targetNode = null, bool $full = true): Upid
    {
        $params = array_filter([
            'newid' => $newVmid,
            'name' => $name,
            'full' => $full ? 1 : 0,
            'storage' => $full ? $storage : null,
            'pool' => $pool,
            'target' => $targetNode !== $this->node ? $targetNode : null,
        ], fn ($value) => $value !== null);

        return $this->upid($this->client->post($this->path($templateVmid, 'clone'), $params));
    }

    /**
     * Current configuration, or null when the VM doesn't exist on this node.
     *
     * @return array<string, mixed>|null
     */
    public function config(int $vmid): ?array
    {
        try {
            $data = $this->client->get($this->path($vmid, 'config'));
        } catch (ProxmoxRequestFailed $e) {
            if (self::isMissingVm($e)) {
                return null;
            }

            throw $e;
        }

        /** @var array<string, mixed> */
        return is_array($data) ? $data : [];
    }

    /**
     * Whether a failed request means the VM does not exist on the node it was asked on.
     */
    public static function isMissingVm(ProxmoxRequestFailed $e): bool
    {
        // Proxmox answers 500 "Configuration file ... does not exist" for unknown VMs.
        return $e->status === 500 && str_contains($e->getMessage(), 'does not exist');
    }

    /**
     * Change configuration synchronously (PUT). Keys listed in $delete are removed.
     *
     * @param  array<string, scalar>  $params
     * @param  list<string>  $delete
     */
    public function updateConfig(int $vmid, array $params, array $delete = []): void
    {
        if ($delete !== []) {
            $params['delete'] = implode(',', $delete);
        }

        $this->client->put($this->path($vmid, 'config'), $params);
    }

    /**
     * Grow a disk to an absolute size (Proxmox cannot shrink disks).
     * Returns a task on Proxmox versions where resizing is asynchronous.
     */
    public function resize(int $vmid, string $disk, int $sizeMib): ?Upid
    {
        $result = $this->client->put($this->path($vmid, 'resize'), ['disk' => $disk, 'size' => "{$sizeMib}M"]);

        return is_string($result) && str_starts_with($result, 'UPID:') ? Upid::parse($result) : null;
    }

    /**
     * @return array{status: string, uptime: int|null}
     */
    public function status(int $vmid): array
    {
        $data = $this->client->get($this->path($vmid, 'status/current'));
        $data = is_array($data) ? $data : [];

        return [
            'status' => is_string($data['status'] ?? null) ? $data['status'] : 'unknown',
            'uptime' => is_numeric($data['uptime'] ?? null) ? (int) $data['uptime'] : null,
        ];
    }

    /**
     * What the VM is doing right now: CPU, memory, and its network and disk counters (docs/adr/0036).
     */
    public function stats(int $vmid): VmStats
    {
        $data = $this->client->get($this->path($vmid, 'status/current'));

        /** @var array<string, mixed> $data */
        $data = is_array($data) ? $data : [];

        return VmStats::fromArray($data);
    }

    /**
     * Averages per minute of the last hour, oldest first (docs/adr/0036).
     *
     * @return list<RrdPoint>
     */
    public function rrd(int $vmid): array
    {
        $points = array_map(
            RrdPoint::fromArray(...),
            ClusterResource::listOfArrays($this->client->get($this->path($vmid, 'rrddata'), ['timeframe' => 'hour', 'cf' => 'AVERAGE'])),
        );
        usort($points, fn (RrdPoint $a, RrdPoint $b) => $a->time <=> $b->time);

        return $points;
    }

    /**
     * A shutdown is forced (hard stop) after 60 seconds unless $forceStop is
     * false; then it waits up to 120 seconds and fails if the guest is still on.
     *
     * @param  'start'|'stop'|'shutdown'|'reboot'  $action
     */
    public function power(int $vmid, string $action, bool $forceStop = true): Upid
    {
        $params = match ($action) {
            'shutdown' => $forceStop ? ['forceStop' => 1, 'timeout' => 60] : ['timeout' => 120],
            'reboot' => ['timeout' => 120],
            default => [],
        };

        return $this->upid($this->client->post($this->path($vmid, "status/{$action}"), $params));
    }

    /**
     * Open a VNC listener for a websocket console (vncwebsocket). Proxmox only
     * waits a few seconds for the connection. With generate-password, the VNC
     * password is separate from the ticket, so the ticket never reaches the browser.
     */
    public function vncProxy(int $vmid): VncProxyTicket
    {
        $data = $this->client->post($this->path($vmid, 'vncproxy'), ['websocket' => 1, 'generate-password' => 1]);

        return VncProxyTicket::fromApi(is_array($data) ? $data : []);
    }

    /**
     * What Proxmox says about moving the VM to $target (docs/adr/0037 §2).
     */
    public function migrationPreconditions(int $vmid, string $target): MigrationPreconditions
    {
        $data = $this->client->get($this->path($vmid, 'migrate'), ['target' => $target]);

        return MigrationPreconditions::fromArray(is_array($data) ? $data : []);
    }

    /**
     * Move the VM to another node of the cluster. A running VM moves live
     * ($online); its local disks are copied to $targetStorage, or to a
     * storage of the same name when null. Disks on shared storage stay.
     */
    public function migrate(int $vmid, string $target, bool $online, ?string $targetStorage = null, bool $withLocalDisks = false, ?int $bwlimitKib = null): Upid
    {
        $params = array_filter([
            'target' => $target,
            'online' => $online ? 1 : null,
            'with-local-disks' => $online && $withLocalDisks ? 1 : null,
            'targetstorage' => $targetStorage,
            'bwlimit' => $bwlimitKib,
        ], fn ($value) => $value !== null);

        return $this->upid($this->client->post($this->path($vmid, 'migrate'), $params));
    }

    /**
     * Move the VM to another cluster (Proxmox: experimental, docs/adr/0037).
     * Every disk is copied to $targetStorage and net0 is put on $targetBridge;
     * with $delete the source VM is removed once the move succeeded, otherwise
     * Proxmox keeps it stopped and locked. A refusal never repeats the token
     * secret of the endpoint.
     *
     * @throws ProxmoxRequestFailed
     */
    public function remoteMigrate(int $vmid, RemoteEndpoint $endpoint, int $targetVmid, string $targetStorage, string $targetBridge, bool $online, bool $delete = true, ?int $bwlimitKib = null): Upid
    {
        $params = array_filter([
            'target-endpoint' => $endpoint->toParameter(),
            'target-vmid' => $targetVmid,
            'target-storage' => $targetStorage,
            'target-bridge' => $targetBridge,
            'online' => $online ? 1 : null,
            'delete' => $delete ? 1 : null,
            'bwlimit' => $bwlimitKib,
        ], fn ($value) => $value !== null);

        try {
            return $this->upid($this->client->post($this->path($vmid, 'remote_migrate'), $params));
        } catch (ProxmoxRequestFailed $e) {
            throw new ProxmoxRequestFailed($endpoint->mask($e->getMessage()), $e->status);
        }
    }

    /**
     * The VM's snapshots by name with their descriptions and times, without
     * Proxmox's "current" entry (docs/adr/0051).
     *
     * @return array<string, array{description: string, time: int|null}>
     */
    public function snapshots(int $vmid): array
    {
        $snapshots = [];

        foreach (ClusterResource::listOfArrays($this->client->get($this->path($vmid, 'snapshot'))) as $snapshot) {
            $name = $snapshot['name'] ?? null;

            if (is_string($name) && $name !== 'current') {
                $snapshots[$name] = [
                    'description' => is_string($snapshot['description'] ?? null) ? $snapshot['description'] : '',
                    'time' => is_numeric($snapshot['snaptime'] ?? null) ? (int) $snapshot['snaptime'] : null,
                ];
            }
        }

        return $snapshots;
    }

    /**
     * Snapshot the disks and configuration, never the RAM (docs/adr/0051 §3).
     */
    public function snapshot(int $vmid, string $name, string $description): Upid
    {
        return $this->upid($this->client->post($this->path($vmid, 'snapshot'), [
            'snapname' => $name,
            'description' => $description,
            'vmstate' => 0,
        ]));
    }

    /**
     * Take the disks and configuration back to the snapshot; the VM is left as Proxmox leaves it.
     */
    public function rollback(int $vmid, string $name): Upid
    {
        return $this->upid($this->client->post($this->path($vmid, 'snapshot/'.rawurlencode($name).'/rollback')));
    }

    public function deleteSnapshot(int $vmid, string $name): Upid
    {
        return $this->upid($this->client->delete($this->path($vmid, 'snapshot/'.rawurlencode($name))));
    }

    /**
     * Delete the VM and all of its disks.
     */
    public function destroy(int $vmid): Upid
    {
        return $this->upid($this->client->delete($this->path($vmid), [
            'purge' => 1,
            'destroy-unreferenced-disks' => 1,
        ]));
    }

    /**
     * The VM firewall options anti-spoofing needs (docs/adr/0033): IP filter
     * (also the ARP sender addresses through ebtables) and MAC filter, no
     * router advertisements, DHCP and NDP for the VM's own addresses only.
     */
    public const ANTI_SPOOFING_OPTIONS = ['enable' => 1, 'ipfilter' => 1, 'macfilter' => 1, 'radv' => 0, 'ndp' => 1, 'dhcp' => 1];

    /**
     * Rules that keep the VM from answering other guests as a DHCP server
     * (DHCPv4 server → client, DHCPv6 server → client). Proxmox's `dhcp`
     * option only lets the client side through and the IP filter allows the
     * VM's own address as source, so without them such replies pass.
     */
    public const DHCP_SERVER_RULES = [
        ['type' => 'out', 'action' => 'DROP', 'proto' => 'udp', 'sport' => '547', 'dport' => '546', 'enable' => 1, 'comment' => 'Proxmotion: no DHCPv6 server'],
        ['type' => 'out', 'action' => 'DROP', 'proto' => 'udp', 'sport' => '67', 'dport' => '68', 'enable' => 1, 'comment' => 'Proxmotion: no DHCP server'],
    ];

    /** Every rule the panel writes has a comment starting with this (docs/adr/0035 §2). */
    public const PANEL_RULE_PREFIX = 'Proxmotion';

    /** The rule fields the panel sets and compares. */
    private const RULE_FIELDS = ['type', 'action', 'proto', 'dport', 'sport', 'source', 'dest', 'enable', 'comment'];

    /**
     * Enable the VM firewall with IP and MAC filtering (anti-spoofing) and
     * an IP set holding at least $addresses. The policies and the rules are
     * the business of applyRules() and setInputPolicy().
     *
     * @param  list<string>  $addresses  IPs the VM may use on net0
     */
    public function enableAntiSpoofing(int $vmid, array $addresses): void
    {
        $base = $this->path($vmid, 'firewall');

        $this->client->put("{$base}/options", self::ANTI_SPOOFING_OPTIONS);

        // Proxmox uses the IP set "ipfilter-net0" to decide which addresses net0 may use.
        if (! $this->hasAntiSpoofingSet($vmid)) {
            $this->client->post("{$base}/ipset", ['name' => 'ipfilter-net0', 'comment' => 'Managed by Proxmotion']);
        }

        $current = array_map(
            fn (array $entry) => is_string($entry['cidr'] ?? null) ? $entry['cidr'] : '',
            ClusterResource::listOfArrays($this->client->get("{$base}/ipset/ipfilter-net0")),
        );

        foreach ($addresses as $address) {
            if (! in_array($address, $current, true)) {
                $this->client->post("{$base}/ipset/ipfilter-net0", ['cidr' => $address]);
            }
        }
    }

    /** Networks of the server's pools that isolate guests (docs/adr/0034). */
    public const ISOLATED_SET = 'pm-isolated';

    /** Gateways of those pools, still reachable. */
    public const GATEWAYS_SET = 'pm-gateways';

    /** Networks the server may not open connections to (Proxmox nodes, management networks). */
    public const PROTECTED_SET = 'pm-protected';

    /**
     * Rules that keep a server away from its neighbours and the protected
     * networks (docs/adr/0034), top to bottom: protected networks before the
     * gateway (on routed setups the gateway is often a node), the gateway
     * before the neighbours. Recognised by their comments.
     */
    public const ISOLATION_RULES = [
        ['type' => 'out', 'action' => 'DROP', 'dest' => '+'.self::PROTECTED_SET, 'enable' => 1, 'comment' => 'Proxmotion: no connections to protected networks'],
        ['type' => 'out', 'action' => 'ACCEPT', 'dest' => '+'.self::GATEWAYS_SET, 'enable' => 1, 'comment' => 'Proxmotion: gateway reachable (out)'],
        ['type' => 'in', 'action' => 'ACCEPT', 'source' => '+'.self::GATEWAYS_SET, 'enable' => 1, 'comment' => 'Proxmotion: gateway reachable (in)'],
        ['type' => 'out', 'action' => 'DROP', 'dest' => '+'.self::ISOLATED_SET, 'enable' => 1, 'comment' => 'Proxmotion: isolated from neighbours (out)'],
        ['type' => 'in', 'action' => 'DROP', 'source' => '+'.self::ISOLATED_SET, 'enable' => 1, 'comment' => 'Proxmotion: isolated from neighbours (in)'],
    ];

    /**
     * Create the isolation IP sets the rules of $sets need (isolationRulesFor())
     * and make their entries exactly $sets (docs/adr/0034). Run before
     * applyRules(): rules refer to them.
     *
     * @param  array<string, list<string>>  $sets  IP set name => entries (addresses or CIDRs)
     */
    public function applyIsolationSets(int $vmid, array $sets): void
    {
        $base = $this->path($vmid, 'firewall');
        $existing = $this->ipsetNames($vmid);

        foreach (self::setsOf(self::isolationRulesFor($sets)) as $name) {
            if (! in_array($name, $existing, true)) {
                $this->client->post("{$base}/ipset", ['name' => $name, 'comment' => 'Managed by Proxmotion']);
            }

            $this->syncIpset($vmid, $name, $sets[$name] ?? []);
        }
    }

    /**
     * Delete the isolation IP sets no rule of $sets needs any more (a pool
     * that stopped isolating, no protected networks left). Run after
     * applyRules(), once nothing refers to them.
     *
     * @param  array<string, list<string>>  $sets
     */
    public function removeUnusedIsolationSets(int $vmid, array $sets): void
    {
        $wanted = self::setsOf(self::isolationRulesFor($sets));

        foreach (array_intersect([self::PROTECTED_SET, self::GATEWAYS_SET, self::ISOLATED_SET], $this->ipsetNames($vmid)) as $name) {
            if (! in_array($name, $wanted, true)) {
                $this->syncIpset($vmid, $name, []);
                $this->client->delete($this->path($vmid, "firewall/ipset/{$name}"));
            }
        }
    }

    /**
     * Make the panel's rules exactly $rules, on top and in this order (docs/adr/0035 §2).
     * The panel's rules are those whose comment starts with PANEL_RULE_PREFIX;
     * when they differ in any way, all of them are deleted and $rules added
     * again. Other rules stay as they are, below them.
     *
     * @param  list<array<string, string|int>>  $rules  top to bottom, each with a comment starting with PANEL_RULE_PREFIX
     */
    public function applyRules(int $vmid, array $rules): void
    {
        $base = $this->path($vmid, 'firewall');
        $current = $this->firewallRules($vmid);

        if (self::panelRulesInPlace($current, $rules)) {
            return;
        }

        $positions = array_map(fn (array $rule) => (int) ($rule['pos'] ?? 0), self::panelRules($current));
        rsort($positions);

        // From the bottom up, so the positions of the others stay right.
        foreach ($positions as $pos) {
            $this->client->delete("{$base}/rules/{$pos}");
        }

        // Proxmox puts a rule added without `pos` at the top: add the last one first.
        foreach (array_reverse($rules) as $rule) {
            $this->client->post("{$base}/rules", $rule);
        }
    }

    /**
     * Whether the panel's rules among $current are exactly $expected, in order
     * (other rules may sit anywhere).
     *
     * @param  list<array<string, mixed>>  $current  as firewallRules() lists them
     * @param  list<array<string, string|int>>  $expected
     */
    public static function panelRulesInPlace(array $current, array $expected): bool
    {
        $ours = self::panelRules($current);

        if (count($ours) !== count($expected)) {
            return false;
        }

        foreach ($expected as $i => $rule) {
            foreach (self::RULE_FIELDS as $field) {
                if ((string) ($ours[$i][$field] ?? '') !== (string) ($rule[$field] ?? '')) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The rules the panel wrote, in order.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    public static function panelRules(array $rules): array
    {
        return array_values(array_filter($rules, fn (array $rule) => str_starts_with((string) ($rule['comment'] ?? ''), self::PANEL_RULE_PREFIX)));
    }

    /**
     * Set the input policy (the output policy stays ACCEPT), unless it already is.
     *
     * @param  'ACCEPT'|'DROP'  $policy
     */
    public function setInputPolicy(int $vmid, string $policy): void
    {
        $options = $this->firewallOptions($vmid);

        if (($options['policy_in'] ?? null) !== $policy || ($options['policy_out'] ?? null) !== 'ACCEPT') {
            $this->client->put($this->path($vmid, 'firewall/options'), ['policy_in' => $policy, 'policy_out' => 'ACCEPT']);
        }
    }

    /**
     * The isolation rules a server needs, in order: the protected networks'
     * rule when there are any, and the gateway and neighbour rules when one
     * of its pools isolates guests (the gateway rules only with a gateway).
     * A rule never refers to an empty IP set.
     *
     * @param  array<string, list<string>>  $sets  IP set name => entries
     * @return list<array<string, string|int>>
     */
    public static function isolationRulesFor(array $sets): array
    {
        $has = fn (string $name) => ($sets[$name] ?? []) !== [];

        return array_values(array_filter(self::ISOLATION_RULES, function (array $rule) use ($has): bool {
            $set = ltrim((string) ($rule['dest'] ?? $rule['source']), '+');

            return match ($set) {
                self::GATEWAYS_SET => $has(self::GATEWAYS_SET) && $has(self::ISOLATED_SET),
                default => $has($set),
            };
        }));
    }

    /**
     * Whether the VM firewall has exactly the expected isolation rules,
     * enabled, unchanged and in order (other rules may sit above, below or
     * between them), and none of the others.
     *
     * @param  list<array<string, mixed>>  $rules  as firewallRules() lists them
     * @param  list<array<string, string|int>>|null  $expected  isolationRulesFor(); null: all of them
     */
    public static function isolationRulesInPlace(array $rules, ?array $expected = null): bool
    {
        $expected ??= self::ISOLATION_RULES;
        $comments = array_column(self::ISOLATION_RULES, 'comment');
        $ours = array_values(array_filter($rules, fn (array $rule) => in_array($rule['comment'] ?? null, $comments, true)));

        if (count($ours) !== count($expected)) {
            return false;
        }

        foreach ($expected as $i => $rule) {
            foreach ($rule as $key => $value) {
                if ((string) ($ours[$i][$key] ?? '') !== (string) $value) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The IP sets some of $rules refer to.
     *
     * @param  list<array<string, string|int>>  $rules
     * @return list<string>
     */
    public static function setsOf(array $rules): array
    {
        return array_values(array_unique(array_map(fn (array $rule) => ltrim((string) ($rule['dest'] ?? $rule['source']), '+'), $rules)));
    }

    /**
     * Make one IP set's entries exactly $entries.
     *
     * @param  list<string>  $entries
     */
    private function syncIpset(int $vmid, string $name, array $entries): void
    {
        $base = $this->path($vmid, 'firewall');
        $current = $this->ipsetEntries($vmid, $name);
        $wanted = array_map(Cidr::canonical(...), $entries);

        foreach ($current as $entry) {
            if (! in_array(Cidr::canonical($entry), $wanted, true)) {
                $this->client->delete("{$base}/ipset/{$name}/".rawurlencode($entry));
            }
        }

        $have = array_map(Cidr::canonical(...), $current);

        foreach ($wanted as $entry) {
            if (! in_array($entry, $have, true)) {
                $this->client->post("{$base}/ipset/{$name}", ['cidr' => $entry]);
            }
        }
    }

    /**
     * Names of the VM firewall's IP sets.
     *
     * @return list<string>
     */
    public function ipsetNames(int $vmid): array
    {
        return array_values(array_filter(array_map(
            fn (array $set) => is_string($set['name'] ?? null) ? $set['name'] : null,
            ClusterResource::listOfArrays($this->client->get($this->path($vmid, 'firewall/ipset'))),
        )));
    }

    /**
     * The entries of one IP set as Proxmox lists them.
     *
     * @return list<string>
     */
    public function ipsetEntries(int $vmid, string $name): array
    {
        return array_values(array_filter(array_map(
            fn (array $entry) => is_string($entry['cidr'] ?? null) ? $entry['cidr'] : null,
            ClusterResource::listOfArrays($this->client->get($this->path($vmid, 'firewall/ipset/'.rawurlencode($name)))),
        )));
    }

    /**
     * The VM firewall's options as Proxmox stores them (options left at their default are missing).
     *
     * @return array<string, mixed>
     */
    public function firewallOptions(int $vmid): array
    {
        $data = $this->client->get($this->path($vmid, 'firewall/options'));

        /** @var array<string, mixed> */
        return is_array($data) ? $data : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function firewallRules(int $vmid): array
    {
        return ClusterResource::listOfArrays($this->client->get($this->path($vmid, 'firewall/rules')));
    }

    /**
     * Whether the VM firewall has an IP set for net0 (Proxmox lists no
     * entries for a missing set, as for an empty one).
     */
    public function hasAntiSpoofingSet(int $vmid): bool
    {
        $names = array_map(fn (array $set) => $set['name'] ?? null, ClusterResource::listOfArrays($this->client->get($this->path($vmid, 'firewall/ipset'))));

        return in_array('ipfilter-net0', $names, true);
    }

    /**
     * Turn on the firewall flag of net0 (the VM firewall filters nothing
     * without it), keeping the rest of the interface. Proxmox applies the
     * flag to a running VM at once.
     *
     * @return bool whether net0 had to be changed
     */
    public function enableInterfaceFirewall(int $vmid): bool
    {
        $net0 = (string) (($this->config($vmid) ?? [])['net0'] ?? '');

        if ($net0 === '' || self::interfaceFirewalled($net0)) {
            return false;
        }

        $options = array_values(array_filter(explode(',', $net0), fn (string $option) => ! str_starts_with($option, 'firewall=')));
        $this->updateConfig($vmid, ['net0' => implode(',', [...$options, 'firewall=1'])]);

        return true;
    }

    /**
     * Whether an interface setting like "virtio=…,bridge=vmbr0,firewall=1" has its firewall on.
     */
    public static function interfaceFirewalled(string $interface): bool
    {
        return in_array('firewall=1', explode(',', $interface), true);
    }

    /**
     * The addresses in the anti-spoofing IP set, without a prefix.
     *
     * @return list<string>
     */
    public function antiSpoofingAddresses(int $vmid): array
    {
        return array_values(array_filter(array_map(
            fn (string $cidr) => explode('/', $cidr)[0],
            $this->antiSpoofingEntries($vmid),
        ), fn (string $address) => $address !== ''));
    }

    /**
     * Remove addresses from the anti-spoofing IP set (e.g. released ones).
     *
     * @param  list<string>  $addresses
     */
    public function removeFromAntiSpoofing(int $vmid, array $addresses): void
    {
        $base = $this->path($vmid, 'firewall/ipset/ipfilter-net0');

        foreach ($this->antiSpoofingEntries($vmid) as $cidr) {
            // Entries may carry a prefix ("203.0.113.10/32"); compare the address.
            if (in_array(explode('/', $cidr)[0], $addresses, true)) {
                $this->client->delete("{$base}/".rawurlencode($cidr));
            }
        }
    }

    /**
     * Configuration changes that wait for the VM's next start (e.g. cores or
     * memory of a running VM without hotplug): key => new value.
     *
     * @return array<string, mixed>
     */
    public function pendingChanges(int $vmid): array
    {
        $pending = [];

        foreach (ClusterResource::listOfArrays($this->client->get($this->path($vmid, 'pending'))) as $entry) {
            if (is_string($entry['key'] ?? null) && (array_key_exists('pending', $entry) || isset($entry['delete']))) {
                $pending[$entry['key']] = $entry['pending'] ?? null;
            }
        }

        return $pending;
    }

    /**
     * The entries of the anti-spoofing IP set as Proxmox lists them ("203.0.113.10" or "203.0.113.10/32").
     *
     * @return list<string>
     */
    private function antiSpoofingEntries(int $vmid): array
    {
        return array_map(
            fn (array $entry) => is_string($entry['cidr'] ?? null) ? $entry['cidr'] : '',
            ClusterResource::listOfArrays($this->client->get($this->path($vmid, 'firewall/ipset/ipfilter-net0'))),
        );
    }

    private function path(int $vmid, string $suffix = ''): string
    {
        return '/nodes/'.rawurlencode($this->node).'/qemu/'.$vmid.($suffix !== '' ? "/{$suffix}" : '');
    }

    private function upid(mixed $result): Upid
    {
        return Upid::parse(is_string($result) ? $result : '');
    }
}
