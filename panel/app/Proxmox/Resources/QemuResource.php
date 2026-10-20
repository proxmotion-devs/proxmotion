<?php

declare(strict_types=1);

namespace App\Proxmox\Resources;

use App\Proxmox\Data\Upid;
use App\Proxmox\Data\VncProxyTicket;
use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\ProxmoxClient;

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
            // Proxmox answers 500 "Configuration file ... does not exist" for unknown VMs.
            if ($e->status === 500 && str_contains($e->getMessage(), 'does not exist')) {
                return null;
            }

            throw $e;
        }

        /** @var array<string, mixed> */
        return is_array($data) ? $data : [];
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
        ['type' => 'out', 'action' => 'DROP', 'proto' => 'udp', 'sport' => '67', 'dport' => '68', 'enable' => 1, 'comment' => 'Proxmotion: no DHCP server'],
        ['type' => 'out', 'action' => 'DROP', 'proto' => 'udp', 'sport' => '547', 'dport' => '546', 'enable' => 1, 'comment' => 'Proxmotion: no DHCPv6 server'],
    ];

    /**
     * Enable the VM firewall with IP and MAC filtering (anti-spoofing).
     * Both policies become ACCEPT unless $setPolicies is false (adopted VMs
     * keep theirs: only the filters are the panel's business there).
     *
     * @param  list<string>  $addresses  IPs the VM may use on net0
     */
    public function enableAntiSpoofing(int $vmid, array $addresses, bool $setPolicies = true): void
    {
        $base = $this->path($vmid, 'firewall');

        $this->client->put("{$base}/options", [
            ...self::ANTI_SPOOFING_OPTIONS,
            ...($setPolicies ? ['policy_in' => 'ACCEPT', 'policy_out' => 'ACCEPT'] : []),
        ]);

        $comments = array_map(fn (array $rule) => $rule['comment'] ?? null, $this->firewallRules($vmid));

        foreach (self::DHCP_SERVER_RULES as $rule) {
            if (! in_array($rule['comment'], $comments, true)) {
                $this->client->post("{$base}/rules", $rule);
            }
        }

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
