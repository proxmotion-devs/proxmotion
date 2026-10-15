<?php

namespace Tests\Feature\Infrastructure;

use App\Domain\Networking\GenerateIpAddresses;
use App\Models\IpPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class GenerateIpAddressesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_usable_addresses_except_the_gateway()
    {
        $pool = IpPool::factory()->create(['network' => '203.0.113.0/29', 'gateway' => '203.0.113.1']);

        $added = app(GenerateIpAddresses::class)->handle($pool);

        $this->assertSame(5, $added);
        $this->assertSame(
            ['203.0.113.2', '203.0.113.3', '203.0.113.4', '203.0.113.5', '203.0.113.6'],
            $pool->addresses()->orderBy('id')->pluck('address')->all(),
        );
    }

    public function test_it_can_be_limited_to_a_range_and_skips_existing_addresses()
    {
        $pool = IpPool::factory()->create(['network' => '203.0.113.0/24', 'gateway' => '203.0.113.1']);
        $generate = app(GenerateIpAddresses::class);

        $this->assertSame(3, $generate->handle($pool, '203.0.113.10', '203.0.113.12'));
        $this->assertSame(2, $generate->handle($pool, '203.0.113.11', '203.0.113.14'));
        $this->assertSame(5, $pool->addresses()->count());
    }

    public function test_it_refuses_huge_ranges()
    {
        $pool = IpPool::factory()->create(['network' => '198.18.0.0/15', 'gateway' => null]);

        $this->expectException(InvalidArgumentException::class);

        app(GenerateIpAddresses::class)->handle($pool);
    }

    public function test_it_does_not_enumerate_ipv6_pools()
    {
        $pool = IpPool::factory()->ipv6()->create();

        $this->expectException(InvalidArgumentException::class);

        app(GenerateIpAddresses::class)->handle($pool);
    }
}
