<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppNotEligible;
use App\Domain\Firewall\ServerFirewalls;
use App\Domain\Networking\ReverseDns\ReverseDns;
use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\Cluster;
use App\Models\IpAddress;
use App\Models\PlacementGroup;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Placement\NodePlacer;
use App\Provisioning\Placement\PlacementRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reserves everything a new server needs (node, storage, VMID, IPs) and queues
 * the Proxmox work. Reservation happens under a per-location lock inside one
 * transaction, so concurrent orders can't take the same capacity, VMID or IP.
 */
final readonly class ServerCreator
{
    public const string INACTIVE_TEMPLATE = 'An inactive OS template can only be used for a server without a billing system: it becomes a test server.';

    public function __construct(
        private NodePlacer $placer,
        private IpAllocator $ips,
        private VmidAllocator $vmids,
        private TaskRunner $tasks,
        private AppInstaller $apps,
        private HostnameGenerator $hostnames,
        private ReverseDns $reverseDns,
    ) {}

    /**
     * @throws ProvisioningException
     * @throws AppNotEligible when the app does not fit the server
     * @throws ValidationException for invalid app inputs
     */
    public function create(NewServer $order, User|ApiToken|null $actor = null): Server
    {
        if ($order->networkModel === NetworkModel::Nat) {
            throw ProvisioningException::unsupportedNetworkModel();
        }

        // An inactive template is not offered to customers yet: administrators try it on a test server (docs/adr/0024 §8).
        if (! $order->osTemplate->is_active && $order->billingSystem !== null) {
            throw new ProvisioningException(self::INACTIVE_TEMPLATE);
        }

        // A package may name the OS templates its servers use; a custom server (no package) may use any (docs/adr/0040).
        if ($order->package !== null && ! $order->package->allowsOsTemplate($order->osTemplate)) {
            throw ProvisioningException::templateNotAllowed();
        }

        if ($order->hostname === null && ($order->location->hostname_template ?? '') === '') {
            throw new ProvisioningException('Enter a hostname: the location :location has no hostname template.', ['location' => $order->location->name]);
        }

        $spec = $order->spec;

        if ($spec->ipv4Count + $spec->ipv6Count === 0) {
            throw ProvisioningException::noAddresses('IPv4');
        }

        if (($order->primaryIpv4 !== null || $order->ipv4Pool !== null) && $spec->ipv4Count === 0) {
            throw new ProvisioningException('A primary IPv4 address needs at least one IPv4 address.');
        }

        if ($order->additionalIpv4 !== [] && $order->primaryIpv4 === null) {
            throw new ProvisioningException('Choose the primary IPv4 address before additional ones.');
        }

        if ($order->additionalIpv4 !== []) {
            $chosen = [$order->primaryIpv4, ...$order->additionalIpv4];

            if (count($chosen) > $spec->ipv4Count) {
                throw new ProvisioningException('This server gets only :count IPv4 addresses.', ['count' => (string) $spec->ipv4Count]);
            }

            if (count(array_unique($chosen)) !== count($chosen)) {
                throw new ProvisioningException('Each IPv4 address can be chosen only once.');
            }
        }

        $appInputs = $order->app === null ? [] : $this->apps->validateOrdered($order->app, $order->appInputs, $order->osTemplate, $spec->cpuCores, $spec->memoryMib, $spec->diskMib, $order->networkModel);

        $server = Cache::lock("provisioning:location:{$order->location->id}", 30)->block(15, function () use ($order, $spec, $appInputs, $actor) {
            // A chosen pool or primary decides the pool; the other IPv4 addresses come from the same one.
            $chosenPool = $order->ipv4Pool;

            if ($chosenPool !== null && ! $this->ips->usableIpv4Pool($chosenPool, $order->location, $order->networkModel)) {
                throw new ProvisioningException('The IPv4 pool :pool is not an active pool of this location for the chosen network model.', ['pool' => $chosenPool->name]);
            }

            $primary = match (true) {
                $order->primaryIpv4 === null => null,
                $chosenPool !== null => $this->ips->freeIpv4Of($chosenPool, $order->primaryIpv4),
                default => $this->ips->freeAddress($order->location, $order->networkModel, IpVersion::V4, $order->primaryIpv4),
            };
            $ipv4Pool = $primary->pool ?? $chosenPool ?? $this->ips->ipv4Pool($order->location, $order->networkModel, $spec->ipv4Count);

            if ($ipv4Pool !== null && $this->ips->freeIpv4Count($ipv4Pool) < $spec->ipv4Count) {
                throw ProvisioningException::noAddresses('IPv4');
            }

            $additional = array_map(fn (string $address) => $this->ips->freeIpv4Of($ipv4Pool ?? throw ProvisioningException::noAddresses('IPv4'), $address), $order->additionalIpv4);
            $ipv6Pool = $this->ips->ipv6Pool($order->location, $order->networkModel, $spec->ipv6Count);

            // Without an IPv6 pool in the location the server gets IPv4 only, but never no address at all.
            if ($ipv4Pool === null && $ipv6Pool === null) {
                throw ProvisioningException::noAddresses('IPv6');
            }

            $bridge = ($ipv4Pool ?? $ipv6Pool)->bridge ?? 'vmbr0';

            $placement = $this->placer->place($order->location, $spec, $order->osTemplate, $bridge, new PlacementRules(
                groups: $this->placementGroups($order),
                node: $order->node,
                storage: $order->storage,
            ));
            $cluster = $placement->node->cluster;
            $vmid = $this->vmids->firstFree($cluster);

            return DB::transaction(function () use ($order, $spec, $placement, $cluster, $vmid, $ipv4Pool, $ipv6Pool, $primary, $additional, $appInputs, $actor) {
                $server = $this->insertServer($order, $placement, $cluster, $vmid);

                if ($primary !== null) {
                    $primary->forceFill(['server_id' => $server->id, 'is_primary' => true, 'assigned_at' => now()])->save();
                }

                foreach ($additional as $address) {
                    $address->forceFill(['server_id' => $server->id, 'assigned_at' => now()])->save();
                }

                $chosen = ($primary !== null ? 1 : 0) + count($additional);
                $this->ips->assign($server, $ipv4Pool, $spec->ipv4Count - $chosen, $ipv6Pool, $spec->ipv6Count);

                if ($order->hostname === null) {
                    $this->nameServer($server, $order);
                }

                if ($order->app !== null) {
                    $this->apps->queueOrdered($server, $order->app, $appInputs, $actor, null);
                }

                return $server;
            });
        });

        $this->tasks->start($server, TaskType::Create, $actor);

        return $server;
    }

    /**
     * The placement groups the server must stay in (docs/adr/0020): the one
     * the administrator chose, else the package's groups in the location,
     * else none (null).
     *
     * @return Collection<int, PlacementGroup>|null
     *
     * @throws ProvisioningException
     */
    private function placementGroups(NewServer $order): ?Collection
    {
        $packageGroups = $order->package?->placementGroups()->with(['nodes', 'storages'])->get() ?? new Collection;
        $chosen = $order->placementGroup;

        if ($chosen !== null) {
            if ($chosen->location_id !== $order->location->id) {
                throw new ProvisioningException('The chosen placement group is not in this location.');
            }

            if ($packageGroups->isNotEmpty() && ! $packageGroups->contains('id', $chosen->id)) {
                throw new ProvisioningException('This package may not use the chosen placement group.');
            }

            return new Collection([$chosen->load(['nodes', 'storages'])]);
        }

        if ($packageGroups->isEmpty()) {
            return null;
        }

        $inLocation = $packageGroups->where('location_id', $order->location->id)->values();

        if ($inLocation->isEmpty()) {
            throw ProvisioningException::noPackageGroup();
        }

        return $inLocation;
    }

    /**
     * The hostname from the location's template (docs/adr/0031), which also
     * becomes the PTR of the primary address of each version. The Proxmox VM
     * is named after it, so cloud-init sets it at the first boot.
     *
     * @throws ProvisioningException
     */
    private function nameServer(Server $server, NewServer $order): void
    {
        $hostname = $this->hostnames->generate($server, $order->location);
        $server->forceFill(['hostname' => $hostname])->save();

        // Primary first within each version (Server::ipAddresses()).
        $byVersion = $server->ipAddresses()->with('pool')->get()->groupBy(fn (IpAddress $address) => $address->pool->version->value);

        foreach ($byVersion as $addresses) {
            $this->reverseDns->setGenerated($addresses->firstOrFail(), $hostname);
        }
    }

    private function insertServer(NewServer $order, Placement\Placement $placement, Cluster $cluster, int $vmid): Server
    {
        $server = new Server;
        $server->forceFill([
            'package_id' => $order->package?->id,
            'location_id' => $order->location->id,
            'cluster_id' => $cluster->id,
            'node_id' => $placement->node->id,
            'storage_id' => $placement->storage->id,
            'os_template_id' => $order->osTemplate->id,
            'vmid' => $vmid,
            // Without one, named from its addresses once they are assigned (nameServer()).
            'hostname' => strtolower($order->hostname ?? ''),
            'status' => ServerStatus::Pending,
            'origin' => ServerOrigin::Provisioned,
            'network_model' => $order->networkModel,
            'cpu_cores' => $order->spec->cpuCores,
            'memory_mib' => $order->spec->memoryMib,
            'disk_mib' => $order->spec->diskMib,
            'port_speed_mbps' => $order->spec->portSpeedMbps,
            'traffic_bytes' => $order->spec->trafficBytes,
            ...$order->spec->limits->columns(),
            ...$order->spec->slots->columns(),
            'root_password' => $order->rootPassword,
            // The billing system may fetch the initial password once (docs/adr/0007).
            'root_password_unrevealed' => true,
            'ssh_keys' => $order->sshKeys,
            'billing_system_id' => $order->billingSystem?->id,
            'external_id' => $order->externalId,
            'external_client_id' => $order->externalClientId,
            // A server of an inactive template is a test server (docs/adr/0024 §8).
            'is_test' => ! $order->osTemplate->is_active,
        ])->save();

        // The package's firewall rule set (docs/adr/0035 §3).
        $ruleset = $order->package?->firewallRuleset;

        if ($ruleset !== null && $ruleset->is_active) {
            ServerFirewalls::startWith($server, $ruleset);
        }

        return $server;
    }
}
