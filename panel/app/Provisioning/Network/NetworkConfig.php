<?php

declare(strict_types=1);

namespace App\Provisioning\Network;

use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\IpAddress;
use App\Models\Server;
use App\Support\Network\Cidr;

/**
 * Builds the Proxmox network and cloud-init settings of a server from its
 * assigned addresses.
 *
 * Limitation: Proxmox's built-in cloud-init configures one IPv4 and one IPv6
 * address per interface. Additional addresses are assigned and allowed by the
 * anti-spoofing filter, but must be configured inside the OS for now.
 */
final class NetworkConfig
{
    /**
     * @param  string|null  $currentNet0  the VM's current net0, to keep its MAC address
     * @return array<string, string>
     */
    public function proxmoxParams(Server $server, ?string $currentNet0 = null): array
    {
        $addresses = $server->ipAddresses()->with('pool')->orderBy('id')->get();
        $ipv4 = $addresses->first(fn (IpAddress $address) => $address->pool->version === IpVersion::V4);
        $ipv6 = $addresses->first(fn (IpAddress $address) => $address->pool->version === IpVersion::V6);
        $primary = $ipv4 ?? $ipv6;

        $params = [];

        if ($primary !== null) {
            $params['net0'] = $this->net0($server, $primary, $this->macOf($currentNet0));
        }

        $ipconfig = [];

        if ($ipv4 !== null) {
            // Routed (failover) IPs are single /32 addresses with an on-link gateway.
            $prefix = $server->network_model === NetworkModel::Routed ? 32 : Cidr::parse($ipv4->pool->network)->prefix;
            $ipconfig[] = "ip={$ipv4->address}/{$prefix}";

            if ($ipv4->pool->gateway !== null) {
                $ipconfig[] = "gw={$ipv4->pool->gateway}";
            }
        }

        if ($ipv6 !== null) {
            $ipconfig[] = "ip6={$ipv6->address}/".Cidr::parse($ipv6->pool->network)->prefix;

            if ($ipv6->pool->gateway !== null) {
                $ipconfig[] = "gw6={$ipv6->pool->gateway}";
            }
        }

        if ($ipconfig !== []) {
            $params['ipconfig0'] = implode(',', $ipconfig);
        }

        $nameservers = $addresses->flatMap(fn (IpAddress $address) => $address->pool->nameservers ?? [])->unique()->values();

        if ($nameservers->isNotEmpty()) {
            $params['nameserver'] = $nameservers->implode(' ');
        }

        return $params;
    }

    /**
     * Every address the server may use (for the anti-spoofing IP set).
     *
     * @return list<string>
     */
    public function allowedAddresses(Server $server): array
    {
        $addresses = [];

        foreach ($server->ipAddresses()->orderBy('id')->get() as $address) {
            $addresses[] = $address->address;
        }

        return $addresses;
    }

    private function net0(Server $server, IpAddress $primary, ?string $currentMac): string
    {
        // A provider-issued virtual MAC (failover IPs) wins; otherwise keep the
        // VM's MAC so re-running provisioning doesn't change it.
        $mac = $primary->mac_address ?? $currentMac;
        $parts = [$mac !== null ? "virtio={$mac}" : 'virtio', "bridge={$primary->pool->bridge}", 'firewall=1'];

        if ($primary->pool->vlan_tag !== null) {
            $parts[] = "tag={$primary->pool->vlan_tag}";
        }

        $speed = $server->package->port_speed_mbps;

        if ($speed !== null) {
            // Proxmox limits bandwidth in MB/s.
            $parts[] = 'rate='.rtrim(rtrim(number_format($speed / 8, 3, '.', ''), '0'), '.');
        }

        return implode(',', $parts);
    }

    /**
     * "virtio=BC:24:11:AA:BB:CC,bridge=vmbr0" → "BC:24:11:AA:BB:CC"
     */
    private function macOf(?string $net0): ?string
    {
        if ($net0 !== null && preg_match('/^\w+=([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})/', $net0, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
