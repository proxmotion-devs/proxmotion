<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

use InvalidArgumentException;

/**
 * A Proxmox task identifier, e.g. "UPID:pve1:0001A2B3:0C4D5E6F:6512ABCD:qmclone:100:root@pam:".
 */
final readonly class Upid
{
    private function __construct(
        public string $value,
        public string $node,
        public string $type,
        public string $id,
    ) {}

    public static function parse(string $value): self
    {
        $parts = explode(':', $value);

        if (count($parts) < 8 || $parts[0] !== 'UPID' || $parts[1] === '') {
            throw new InvalidArgumentException('Not a valid Proxmox UPID.');
        }

        return new self($value, $parts[1], $parts[5], $parts[6]);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
