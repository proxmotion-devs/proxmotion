<?php

namespace Tests\Unit;

use App\Domain\Networking\ReverseDns\PtrName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PtrNameTest extends TestCase
{
    public function test_ipv4_addresses_are_reversed_under_in_addr_arpa()
    {
        $this->assertSame('5.113.0.203.in-addr.arpa', PtrName::for('203.0.113.5'));
    }

    public function test_ipv6_addresses_use_one_nibble_per_label()
    {
        $this->assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1.0.8.b.d.0.1.0.0.2.ip6.arpa',
            PtrName::for('2001:db8:100::1'),
        );
    }

    public function test_zone_membership()
    {
        $this->assertTrue(PtrName::inZone('5.113.0.203.in-addr.arpa', '113.0.203.in-addr.arpa.'));
        $this->assertTrue(PtrName::inZone('5.113.0.203.in-addr.arpa', '203.in-addr.arpa'));
        $this->assertFalse(PtrName::inZone('5.113.0.203.in-addr.arpa', '13.0.203.in-addr.arpa'));
        $this->assertFalse(PtrName::inZone('5.113.0.203.in-addr.arpa', ''));
    }

    public function test_invalid_addresses_are_refused()
    {
        $this->expectException(InvalidArgumentException::class);

        PtrName::for('not-an-ip');
    }
}
