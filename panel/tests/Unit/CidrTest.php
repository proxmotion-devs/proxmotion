<?php

namespace Tests\Unit;

use App\Support\Network\Cidr;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CidrTest extends TestCase
{
    public function test_it_normalises_to_the_network_address()
    {
        $this->assertSame('203.0.113.0/24', (string) Cidr::parse('203.0.113.77/24'));
        $this->assertSame('2001:db8:100::/48', (string) Cidr::parse('2001:db8:100:abcd::1/48'));
        $this->assertSame('203.0.113.0/25', (string) Cidr::parse('203.0.113.127/25'));
    }

    public function test_it_checks_membership()
    {
        $v4 = Cidr::parse('203.0.113.0/28');
        $this->assertTrue($v4->contains('203.0.113.14'));
        $this->assertFalse($v4->contains('203.0.113.16'));
        $this->assertFalse($v4->contains('2001:db8::1'));

        $v6 = Cidr::parse('2001:db8:100::/48');
        $this->assertTrue($v6->contains('2001:db8:100:ffff::1'));
        $this->assertFalse($v6->contains('2001:db8:101::1'));
    }

    public function test_it_lists_ipv4_hosts()
    {
        $cidr = Cidr::parse('203.0.113.0/29');

        $this->assertSame(6, $cidr->hostCount());
        $this->assertSame(
            ['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4', '203.0.113.5', '203.0.113.6'],
            iterator_to_array($cidr->hosts(), false),
        );

        $this->assertSame(['203.0.113.9'], iterator_to_array(Cidr::parse('203.0.113.9/32')->hosts(), false));
        $this->assertSame(2, Cidr::parse('203.0.113.8/31')->hostCount());
    }

    public function test_it_computes_addresses_by_offset()
    {
        $this->assertSame('2001:db8:100::2', Cidr::parse('2001:db8:100::/64')->address(2));
        $this->assertSame('2001:db8:100::100', Cidr::parse('2001:db8:100::/64')->address(256));
        $this->assertSame('203.0.113.10', Cidr::parse('203.0.113.0/24')->address(10));

        $this->expectException(InvalidArgumentException::class);
        Cidr::parse('203.0.113.0/30')->address(4);
    }

    #[DataProvider('invalidNetworks')]
    public function test_it_rejects_invalid_networks(string $cidr)
    {
        $this->assertFalse(Cidr::isValid($cidr));

        $this->expectException(InvalidArgumentException::class);
        Cidr::parse($cidr);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNetworks(): array
    {
        return [
            'no prefix' => ['203.0.113.0'],
            'v4 prefix too long' => ['203.0.113.0/33'],
            'v6 prefix too long' => ['2001:db8::/129'],
            'bad address' => ['203.0.113.300/24'],
            'non-numeric prefix' => ['203.0.113.0/abc'],
            'negative prefix' => ['203.0.113.0/-1'],
        ];
    }
}
