<?php

declare(strict_types=1);

namespace App\Domain\Networking\ReverseDns;

/**
 * Publishes PTR records somewhere (docs/adr/0003). Names are fully
 * qualified without the trailing dot, e.g. "5.113.0.203.in-addr.arpa".
 */
interface ReverseDnsDriver
{
    /**
     * False for "record only": PTRs are kept in the panel and exported by hand.
     */
    public function publishes(): bool;

    /**
     * @throws ReverseDnsFailed
     */
    public function set(string $zone, string $ptrName, string $hostname): void;

    /**
     * @throws ReverseDnsFailed
     */
    public function remove(string $zone, string $ptrName): void;

    /**
     * Check the connection and credentials ("Test connection" in admin).
     *
     * @throws ReverseDnsFailed
     */
    public function test(): void;
}
