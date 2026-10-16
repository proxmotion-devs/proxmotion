<?php

namespace Tests\Feature\Networking;

use App\Domain\Networking\ReverseDns\ForwardDns;
use App\Models\AuditLog;
use App\Models\DnsProvider;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\IpAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\TestCase;

class ReverseDnsTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private Server $server;

    private IpAddress $address;

    private DnsProvider $powerDns;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['ns1.example.com*' => Http::response(null, 204)]);

        $this->buildInfrastructure();
        $this->server = Server::factory()->for($this->node)->create();
        $this->address = $this->ipv4Pool->addresses()->orderBy('id')->firstOrFail();
        $this->address->forceFill(['server_id' => $this->server->id])->save();

        $this->powerDns = new DnsProvider;
        $this->powerDns->forceFill([
            'name' => 'Our PowerDNS',
            'driver' => 'powerdns',
            'options' => ['api_url' => 'https://ns1.example.com:8081', 'server_id' => 'localhost'],
            'credentials' => ['api_key' => 'secret-pdns-key'],
        ])->save();
    }

    private function usePowerDns(bool $requireForward = false): void
    {
        $this->ipv4Pool->forceFill([
            'dns_provider_id' => $this->powerDns->id,
            'reverse_zone' => '113.0.203.in-addr.arpa',
            'rdns_require_forward' => $requireForward,
        ])->save();
    }

    private function setPtr(?string $hostname, ?string $address = null): TestResponse
    {
        return $this->actingAs(User::factory()->withTwoFactor()->create())
            ->from(route('admin.servers.show', $this->server))
            ->post(route('admin.servers.rdns', $this->server), ['address' => $address ?? $this->address->address, 'hostname' => $hostname]);
    }

    public function test_the_ptr_of_a_server_address_is_published_through_powerdns()
    {
        $this->usePowerDns();
        $ptrName = implode('.', array_reverse(explode('.', $this->address->address))).'.in-addr.arpa.';

        $this->setPtr('Mail.Example.com.')->assertSessionHasNoErrors();

        $address = $this->address->refresh();
        $this->assertSame('mail.example.com', $address->ptr);
        $this->assertNotNull($address->ptr_synced_at);

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && $request->url() === 'https://ns1.example.com:8081/api/v1/servers/localhost/zones/113.0.203.in-addr.arpa.'
            && $request->hasHeader('X-API-Key', 'secret-pdns-key')
            && $request['rrsets'] === [[
                'name' => $ptrName,
                'type' => 'PTR',
                'ttl' => 3600,
                'changetype' => 'REPLACE',
                'records' => [['content' => 'mail.example.com.', 'disabled' => false]],
            ]]);

        $log = AuditLog::query()->sole();
        $this->assertSame('rdns.updated', $log->action);
        $this->assertSame('mail.example.com', $log->properties['to'] ?? null);
    }

    public function test_an_empty_hostname_removes_the_ptr()
    {
        $this->usePowerDns();
        $this->address->forceFill(['ptr' => 'old.example.com', 'ptr_synced_at' => now()])->save();

        $this->setPtr('');

        $this->assertNull($this->address->refresh()->ptr);
        Http::assertSent(fn (Request $request) => ($request['rrsets'][0]['changetype'] ?? null) === 'DELETE');
    }

    public function test_record_only_pools_keep_the_ptr_pending_for_export()
    {
        $this->setPtr('mail.example.com');

        $address = $this->address->refresh();
        $this->assertSame('mail.example.com', $address->ptr);
        $this->assertNull($address->ptr_synced_at);
        Http::assertNothingSent();

        $admin = User::factory()->withTwoFactor()->create();
        $csv = $this->actingAs($admin)->get(route('admin.ip-pools.rdns-export', $this->ipv4Pool))->streamedContent();
        $this->assertStringContainsString("{$this->address->address},", $csv);
        $this->assertStringContainsString('mail.example.com.,yes', $csv);

        $this->post(route('admin.ip-pools.rdns-mark-applied', $this->ipv4Pool));
        $this->assertNotNull($this->address->refresh()->ptr_synced_at);
    }

    public function test_forward_confirmation_can_be_required()
    {
        $this->usePowerDns(requireForward: true);
        $this->app->instance(ForwardDns::class, new class extends ForwardDns
        {
            public function resolvesTo(string $hostname, string $address): bool
            {
                return $hostname === 'confirmed.example.com';
            }
        });

        $this->setPtr('unconfirmed.example.com')->assertSessionHasErrors('hostname');
        $this->assertNull($this->address->refresh()->ptr);

        $this->setPtr('confirmed.example.com')->assertSessionHasNoErrors();
        $this->assertSame('confirmed.example.com', $this->address->refresh()->ptr);
    }

    public function test_hostnames_are_validated()
    {
        foreach (['localhost', 'bad_name.example.com', '-x.example.com', 'a b.example.com', str_repeat('a', 64).'.example.com'] as $hostname) {
            $this->setPtr($hostname)->assertSessionHasErrors('hostname');
        }

        $this->assertNull($this->address->refresh()->ptr);
    }

    public function test_the_ptr_of_another_servers_address_cannot_be_set()
    {
        $other = $this->ipv4Pool->addresses()->where('id', '!=', $this->address->id)->firstOrFail();

        $this->setPtr('mail.example.com', address: $other->address)->assertSessionHasErrors('address');
        $this->assertNull($other->refresh()->ptr);
    }

    public function test_released_addresses_lose_their_ptr()
    {
        $this->usePowerDns();
        $this->address->forceFill(['ptr' => 'mail.example.com', 'ptr_synced_at' => now()])->save();

        app(IpAllocator::class)->release($this->server);

        $address = $this->address->refresh();
        $this->assertNull($address->server_id);
        $this->assertNull($address->ptr);
        Http::assertSent(fn (Request $request) => ($request['rrsets'][0]['changetype'] ?? null) === 'DELETE');
    }

    public function test_admins_manage_dns_providers_and_api_keys_stay_write_only()
    {
        $admin = User::factory()->withTwoFactor()->create();
        $this->actingAs($admin);

        $this->post(route('admin.dns-providers.store'), [
            'name' => 'Second', 'driver' => 'powerdns', 'api_url' => 'https://ns1.example.com:8081', 'server_id' => '', 'api_key' => 'another-secret',
        ])->assertSessionHasNoErrors();
        $created = DnsProvider::query()->where('name', 'Second')->sole();
        $this->assertSame('localhost', $created->options['server_id'] ?? null);

        $this->put(route('admin.dns-providers.update', $created), [
            'name' => 'Renamed', 'driver' => 'powerdns', 'api_url' => 'https://ns1.example.com:8081', 'server_id' => 'localhost', 'api_key' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame('another-secret', $created->refresh()->credentials['api_key'] ?? null, 'An empty key keeps the stored one.');

        $this->get(route('admin.dns-providers.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/dns-providers/Index')
                ->where('providers.0.has_api_key', true)
                ->missing('providers.0.api_key')
                ->missing('providers.0.credentials'))
            ->assertDontSee('another-secret')
            ->assertDontSee('secret-pdns-key');
    }

    public function test_providers_in_use_cannot_be_deleted()
    {
        $this->usePowerDns();
        $this->actingAs(User::factory()->withTwoFactor()->create());

        $this->delete(route('admin.dns-providers.destroy', $this->powerDns));

        $this->assertModelExists($this->powerDns);
    }

    public function test_the_connection_can_be_tested()
    {
        $this->actingAs(User::factory()->withTwoFactor()->create());

        $this->post(route('admin.dns-providers.test', $this->powerDns));

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://ns1.example.com:8081/api/v1/servers/localhost');
    }

    public function test_a_pool_with_a_provider_needs_a_reverse_zone_that_covers_its_network()
    {
        $this->actingAs(User::factory()->withTwoFactor()->create());
        $payload = [
            'name' => $this->ipv4Pool->name,
            'network_model' => 'bridged',
            'gateway' => '203.0.113.1',
            'bridge' => 'vmbr0',
            'nameservers' => [],
            'is_active' => true,
            'dns_provider' => $this->powerDns->uuid,
        ];

        $this->put(route('admin.ip-pools.update', $this->ipv4Pool), $payload)->assertSessionHasErrors('reverse_zone');
        $this->put(route('admin.ip-pools.update', $this->ipv4Pool), [...$payload, 'reverse_zone' => '100.51.198.in-addr.arpa'])->assertSessionHasErrors('reverse_zone');
        $this->put(route('admin.ip-pools.update', $this->ipv4Pool), [...$payload, 'reverse_zone' => '113.0.203.in-addr.arpa.'])->assertSessionHasNoErrors();

        $pool = $this->ipv4Pool->refresh();
        $this->assertSame($this->powerDns->id, $pool->dns_provider_id);
        $this->assertSame('113.0.203.in-addr.arpa', $pool->reverse_zone);
    }
}
