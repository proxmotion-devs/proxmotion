<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\IpVersion;
use App\Models\IpAddress;
use App\Models\Location;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Rules\Hostname;

/**
 * Hostnames of servers ordered without one (docs/adr/0031), from the
 * location's template: {ip} is the primary IPv4 address with dashes
 * (203-0-113-5), or the primary IPv6 address written out in full with dashes
 * on a server without IPv4; {vmid} the VMID; {location} the location's code.
 * Its Proxmox VM is named after the hostname like any other (VmLabels tags it
 * with its addresses).
 */
final class HostnameGenerator
{
    public const array PLACEHOLDERS = ['{ip}', '{vmid}', '{location}'];

    /**
     * What is wrong with a template (an English translation key), or null.
     * It is tried with the longest values the placeholders can take.
     */
    public static function problem(string $template, string $locationCode): ?string
    {
        preg_match_all('/\{[^{}]*\}/', $template, $matches);

        if (array_diff($matches[0], self::PLACEHOLDERS) !== []) {
            return 'The hostname template may only use {ip}, {vmid} and {location}.';
        }

        if (! str_contains($template, '{ip}') && ! str_contains($template, '{vmid}')) {
            return 'The hostname template needs {ip} or {vmid}, so that each server gets its own name.';
        }

        foreach (['203.0.113.255', '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff'] as $address) {
            if (! Hostname::isValid(self::render($template, $address, 999999999, $locationCode))) {
                return 'The hostname template must give a valid hostname, such as ip-{ip}.example.com.';
            }
        }

        return null;
    }

    /**
     * Why a server of the location (by uuid) needs a hostname given
     * (translated), or null when the location's template names it.
     */
    public static function missingHostname(string $locationUuid): ?string
    {
        $location = Location::query()->where('uuid', $locationUuid)->first();

        if ($location === null || ($location->hostname_template ?? '') !== '') {
            return null;
        }

        return __('Enter a hostname: the location :location has no hostname template.', ['location' => $location->name]);
    }

    /**
     * The name a server with the address 203.0.113.5 and VMID 100 would get.
     */
    public static function example(string $template, string $locationCode): string
    {
        return self::render($template, '203.0.113.5', 100, $locationCode);
    }

    public static function render(string $template, string $address, int $vmid, string $locationCode): string
    {
        return strtolower(strtr($template, [
            '{ip}' => self::addressLabel($address),
            '{vmid}' => (string) $vmid,
            '{location}' => $locationCode,
        ]));
    }

    /**
     * The name of a server whose addresses have been assigned.
     *
     * @throws ProvisioningException when the location has no template or the name is invalid
     */
    public function generate(Server $server, Location $location): string
    {
        $template = $location->hostname_template;

        if ($template === null || $template === '') {
            throw new ProvisioningException('Enter a hostname: the location :location has no hostname template.', ['location' => $location->name]);
        }

        $address = $this->primaryAddress($server) ?? throw ProvisioningException::noAddresses('IPv4');
        $hostname = self::render($template, $address->address, $server->vmid, $location->code);

        if (! Hostname::isValid($hostname)) {
            throw new ProvisioningException('The hostname template of the location :location does not give a valid hostname.', ['location' => $location->name]);
        }

        return $hostname;
    }

    /**
     * The primary IPv4 address, else the primary IPv6 address.
     */
    public function primaryAddress(Server $server): ?IpAddress
    {
        // Ordered primary first, then oldest (docs/adr/0011).
        $addresses = $server->ipAddresses()->with('pool')->get();

        return $addresses->first(fn (IpAddress $address) => $address->pool->version === IpVersion::V4)
            ?? $addresses->first(fn (IpAddress $address) => $address->pool->version === IpVersion::V6);
    }

    /**
     * An address with dashes: 203-0-113-5, or an IPv6 address written out in
     * full (2001-0db8-0000-…), for names and tags that take no colons.
     */
    public static function addressLabel(string $address): string
    {
        $binary = inet_pton($address);

        if ($binary === false || strlen($binary) === 4) {
            return str_replace('.', '-', $address);
        }

        return implode('-', str_split(bin2hex($binary), 4));
    }
}
