<?php

namespace Tests\Unit;

use App\Domain\Networking\AddressInput;
use App\Enums\IpVersion;
use App\Support\Network\Cidr;
use PHPUnit\Framework\TestCase;

class AddressInputTest extends TestCase
{
    private function v4(string $input, string $network = '203.0.113.0/24'): AddressInput
    {
        return AddressInput::parse($input, IpVersion::V4, Cidr::parse($network));
    }

    public function test_single_addresses_and_lists_with_any_separator()
    {
        $this->assertSame(
            ['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4'],
            $this->v4("203.0.113.1, 203.0.113.2;203.0.113.3\n203.0.113.4 203.0.113.1")->addresses,
        );
    }

    public function test_full_and_short_ranges()
    {
        $this->assertSame(['203.0.113.10', '203.0.113.11', '203.0.113.12'], $this->v4('203.0.113.10-203.0.113.12')->addresses);
        $this->assertSame(['203.0.113.10', '203.0.113.11', '203.0.113.12'], $this->v4('203.0.113.10-12')->addresses);
    }

    public function test_blocks_expand_to_every_address()
    {
        $this->assertSame(
            ['203.0.113.144', '203.0.113.145', '203.0.113.146', '203.0.113.147'],
            $this->v4('203.0.113.144/30')->addresses,
        );
    }

    public function test_problems_are_reported()
    {
        $input = $this->v4('203.0.113.5, 198.51.100.1, banana, 203.0.113.20-203.0.113.10');

        $this->assertSame(['203.0.113.5'], $input->addresses);
        $this->assertSame(['banana', '203.0.113.20-203.0.113.10'], $input->invalid);
        $this->assertSame(['198.51.100.1'], $input->outside);
    }

    public function test_ipv6_accepts_single_addresses_only()
    {
        $input = AddressInput::parse('2001:DB8:100::10, 2001:db8:100::11-12', IpVersion::V6, Cidr::parse('2001:db8:100::/64'));

        $this->assertSame(['2001:db8:100::10'], $input->addresses);
        $this->assertSame(['2001:db8:100::11-12'], $input->invalid);
    }

    public function test_ipv4_addresses_are_rejected_in_ipv6_pools()
    {
        $input = AddressInput::parse('203.0.113.5', IpVersion::V6, Cidr::parse('2001:db8:100::/64'));

        $this->assertSame([], $input->addresses);
        $this->assertSame(['203.0.113.5'], $input->invalid);
    }

    public function test_huge_inputs_are_refused()
    {
        $this->assertTrue($this->v4('198.18.0.0/20, 198.18.16.0/20', '198.18.0.0/15')->tooMany);
    }
}
