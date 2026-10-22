<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\IpAddress;
use App\Models\Server;
use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\Resources\QemuResource;

/**
 * What tells administrators in Proxmox which server a VM is (docs/adr/0031
 * §2a): the notes (description) name the server and its primary addresses,
 * and each primary address is a tag, so the VM, named after its hostname, can
 * be found by address. Neither is part of the cloud-init data, so changing
 * them later gives cloud-init no new instance.
 */
final class VmLabels
{
    /**
     * The primary address of each version, IPv4 first.
     *
     * @return list<string>
     */
    public static function addresses(Server $server): array
    {
        // Primary first within each version (Server::ipAddresses()).
        return array_values($server->ipAddresses()->with('pool')->get()
            ->sortBy(fn (IpAddress $address) => $address->pool->version->value)
            ->groupBy(fn (IpAddress $address) => $address->pool->version->value)
            ->map(fn ($addresses) => $addresses->firstOrFail()->address)
            ->all());
    }

    /**
     * The VM's notes. Must keep "Server <uuid>": rebuilding from scratch only
     * deletes a VM that names its server (docs/adr/0032 §7).
     */
    public static function description(Server $server): string
    {
        $addresses = self::addresses($server);

        return "Managed by Proxmotion. Server {$server->uuid}"
            .($addresses === [] ? '' : "\n\nPrimary address: ".implode(', ', $addresses));
    }

    /**
     * The VM's tags: those it has, without earlier address tags, and the
     * server's primary addresses (an IPv6 address written out with dashes,
     * since a tag has no colons).
     */
    public static function tags(Server $server, string $current): string
    {
        $kept = array_filter(
            preg_split('/[;,\s]+/', $current, flags: PREG_SPLIT_NO_EMPTY) ?: [],
            fn (string $tag) => ! self::isAddressTag($tag),
        );
        $addresses = array_map(fn (string $address) => str_contains($address, ':') ? HostnameGenerator::addressLabel($address) : $address, self::addresses($server));

        return implode(';', array_values(array_unique([...$kept, ...$addresses])));
    }

    /**
     * Write the notes and tags. Proxmox refuses tags that are not registered
     * when the datacenter's user tag access is limited: the notes stay and
     * the returned note says why the tags are missing.
     */
    public static function apply(QemuResource $qemu, Server $server): ?string
    {
        $qemu->updateConfig($server->vmid, ['description' => self::description($server)]);

        return self::applyTags($qemu, $server);
    }

    /**
     * Write only the tags (the configure step sends the notes with the rest).
     */
    public static function applyTags(QemuResource $qemu, Server $server): ?string
    {
        $current = $qemu->config($server->vmid)['tags'] ?? '';
        $tags = self::tags($server, is_string($current) ? $current : '');

        if ($tags === $current) {
            return null;
        }

        try {
            $qemu->updateConfig($server->vmid, ['tags' => $tags]);
        } catch (ProxmoxRequestFailed $e) {
            return "The address tags were not set: {$e->getMessage()}";
        }

        return null;
    }

    private static function isAddressTag(string $tag): bool
    {
        return filter_var($tag, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            || preg_match('/^[0-9a-f]{4}(-[0-9a-f]{4}){7}$/i', $tag) === 1;
    }
}
