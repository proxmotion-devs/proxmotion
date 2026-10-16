<?php

declare(strict_types=1);

namespace App\Domain\Networking\ReverseDns;

/**
 * The "none" driver: PTRs stay in the panel until an admin exports them and
 * marks them as applied.
 */
final class RecordOnlyDriver implements ReverseDnsDriver
{
    public function publishes(): bool
    {
        return false;
    }

    public function set(string $zone, string $ptrName, string $hostname): void {}

    public function remove(string $zone, string $ptrName): void {}

    public function test(): void {}
}
