<?php

declare(strict_types=1);

namespace App\Provisioning\Exceptions;

use RuntimeException;

/**
 * A server cannot be created as requested. Messages are English translation
 * keys; use translatedMessage() when showing them to users.
 */
class ProvisioningException extends RuntimeException
{
    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(string $message, private readonly array $replace = [])
    {
        parent::__construct($message);
    }

    public function translatedMessage(): string
    {
        return __($this->getMessage(), $this->replace);
    }

    public static function noCapacity(): self
    {
        return new self('No node in this location has enough free capacity for this server.');
    }

    public static function noGroupCapacity(): self
    {
        return new self('No node of the placement group has enough free capacity and a usable storage for this server.');
    }

    public static function noPackageGroup(): self
    {
        return new self('None of the placement groups this package may use is in this location.');
    }

    public static function noTemplate(): self
    {
        return new self('This OS template is not available on any cluster in this location.');
    }

    public static function templateNotAllowed(): self
    {
        return new self('This package may not use the chosen OS template.');
    }

    public static function firewallOff(): self
    {
        return new self('The Proxmox firewall is off on every cluster of this location that has this OS template, so new servers could spoof addresses.');
    }

    public static function noAddresses(string $version): self
    {
        return new self('No free :version addresses are left in this location for the chosen network model.', ['version' => $version]);
    }

    public static function unsupportedNetworkModel(): self
    {
        return new self('This network model is not supported yet.');
    }
}
