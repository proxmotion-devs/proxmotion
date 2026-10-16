<?php

namespace Tests\Feature\Provisioning;

use App\Domain\Networking\GenerateIpAddresses;
use App\Enums\IpVersion;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Network\NetworkConfig;
use App\Provisioning\PrimaryAddressChanger;
use App\Provisioning\ResourceChange;
use App\Provisioning\ResourceChanger;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Changing the primary address (docs/adr/0011): swap with an additional
 * address, or replace it with a free one (the old one is released); cloud-init's
 * ipconfig0 is never touched, the guest script moves the primary instead.
 */
class PrimaryAddressTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    private function server(int $ipv4 = 1, ?string $primary = null): Server
    {
        $spec = new ServerSpec(cpuCores: 1, memoryMib: 1024, diskMib: 10240, ipv4Count: $ipv4, ipv6Count: 0);

        return app(ServerCreator::class)->create($this->order(['spec' => $spec, 'primaryIpv4' => $primary]))->refresh();
    }

    private function change(Server $server, ?string $address, IpVersion $version = IpVersion::V4): Task
    {
        return app(PrimaryAddressChanger::class)->request($server, $version, $address, $this->admin, '127.0.0.1')->refresh();
    }

    /**
     * @return list<string>
     */
    private function addresses(Server $server): array
    {
        return $server->ipAddresses()->pluck('address')->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function guestInputs(Server $server): array
    {
        return json_decode($this->proxmox->guestFiles[$server->vmid]['/etc/proxmotion/addresses.json'], true);
    }

    public function test_an_additional_address_becomes_the_primary()
    {
        $server = $this->server(ipv4: 2);
        [$first, $second] = $this->addresses($server);
        $ipconfig = $this->proxmox->vms[$server->vmid]['config']['ipconfig0'];

        $task = $this->change($server, $second);

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(TaskType::ChangePrimaryAddress, $task->type);
        $this->assertSame([$second, $first], $this->addresses($server), 'Swapped; the count stays.');
        $this->assertEqualsCanonicalizing([$first, $second], $this->proxmox->vms[$server->vmid]['ipset']);
        $this->assertSame($ipconfig, $this->proxmox->vms[$server->vmid]['config']['ipconfig0'], 'cloud-init settings are never changed (ADR 0011).');

        $inputs = $this->guestInputs($server);
        $this->assertSame("{$second}/29", $inputs['primary_ipv4']);
        $this->assertSame('203.0.113.1', $inputs['gateway_ipv4']);
        $this->assertSame("{$first}/29", $inputs['cloudinit_ipv4']);
        $this->assertSame(["{$first}/29"], $inputs['addresses']);

        $audit = AuditLog::query()->where('action', 'server.primary_address_changed')->sole();
        $this->assertSame([$first, $second, false], [$audit->properties['before'], $audit->properties['after'], $audit->properties['released']]);
    }

    public function test_a_free_address_replaces_the_primary_and_the_old_one_is_released()
    {
        $server = $this->server();
        [$old] = $this->addresses($server);
        IpAddress::query()->where('address', $old)->update(['ptr' => 'web.example.com', 'ptr_synced_at' => now()]);

        $task = $this->change($server, null);

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        [$new] = $this->addresses($server);
        $this->assertNotSame($old, $new);
        $this->assertCount(1, $this->addresses($server), 'The count stays.');
        $this->assertSame([$new], $this->proxmox->vms[$server->vmid]['ipset']);

        $released = IpAddress::query()->where('address', $old)->sole();
        $this->assertNull($released->server_id);
        $this->assertFalse($released->is_primary);
        $this->assertNull($released->ptr, 'The PTR of the released address is removed.');

        $inputs = $this->guestInputs($server);
        $this->assertSame("{$new}/29", $inputs['primary_ipv4']);
        $this->assertSame("{$old}/29", $inputs['cloudinit_ipv4'], 'The script removes the old one inside the guest.');
        $this->assertSame([], $inputs['addresses']);
    }

    public function test_a_specific_free_address_can_be_chosen()
    {
        $server = $this->server();

        $this->change($server, '203.0.113.6');

        $this->assertSame(['203.0.113.6'], $this->addresses($server));
    }

    public function test_unusable_addresses_are_refused()
    {
        $server = $this->server(ipv4: 2);
        $other = $this->server();
        [$first] = $this->addresses($server);
        [$taken] = $this->addresses($other);
        $reserved = IpAddress::query()->whereNull('server_id')->firstOrFail();
        $reserved->forceFill(['is_reserved' => true])->save();

        foreach ([$first => 'already the primary', $taken => 'not a free address', $reserved->address => 'not a free address', '198.51.100.5' => 'not a free address', '203.0.113.1' => 'not a free address'] as $address => $reason) {
            try {
                $this->change($server, $address);
                $this->fail("{$address} was accepted.");
            } catch (ProvisioningException $e) {
                $this->assertStringContainsString($reason, $e->getMessage(), $address);
            }
        }

        $this->assertSame(0, Task::query()->where('type', TaskType::ChangePrimaryAddress)->count());
    }

    public function test_pools_on_another_bridge_are_not_used()
    {
        $server = $this->server();
        $other = IpPool::factory()->for($this->location)->create(['network' => '198.51.100.0/29', 'gateway' => '198.51.100.1', 'bridge' => 'vmbr9']);
        app(GenerateIpAddresses::class)->handle($other);

        $this->expectException(ProvisioningException::class);

        $this->change($server, '198.51.100.3');
    }

    public function test_only_active_servers_change_their_primary()
    {
        $server = $this->server();
        $server->forceFill(['status' => 'suspended'])->save();

        $this->expectException(ProvisioningException::class);

        $this->change($server, null);
    }

    public function test_the_new_primary_stays_through_downgrades_and_reinstalls()
    {
        $server = $this->server(ipv4: 3);
        [, , $third] = $this->addresses($server);
        $this->change($server, $third);

        $spec = new ServerSpec(cpuCores: 1, memoryMib: 1024, diskMib: 10240, ipv4Count: 1, ipv6Count: 0);
        app(ResourceChanger::class)->request($server, new ResourceChange($spec, null), $this->admin, null);
        $this->assertSame([$third], $this->addresses($server), 'A downgrade never releases the primary.');

        $this->assertStringContainsString("ip={$third}/29", app(NetworkConfig::class)->proxmoxParams($server)['ipconfig0'], 'A reinstall configures it through cloud-init.');
        app(TaskRunner::class)->start($server, TaskType::Reinstall, $this->admin);
        $this->assertStringContainsString("ip={$third}/29", $this->proxmox->vms[$server->vmid]['config']['ipconfig0']);
    }

    public function test_a_server_can_be_created_with_a_chosen_primary()
    {
        $server = $this->server(ipv4: 2, primary: '203.0.113.5');

        $this->assertSame('203.0.113.5', $this->addresses($server)[0]);
        $this->assertCount(2, $this->addresses($server));
        $this->assertStringContainsString('ip=203.0.113.5/29', $this->proxmox->vms[$server->vmid]['config']['ipconfig0']);
    }

    public function test_a_taken_primary_refuses_the_order()
    {
        [$taken] = $this->addresses($this->server());

        $this->expectException(ProvisioningException::class);

        $this->server(primary: $taken);
    }

    public function test_admins_change_the_primary_from_the_server_page()
    {
        $server = $this->server(ipv4: 2);
        [$first, $second] = $this->addresses($server);

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('server.addresses.0.primary', true)
                ->where('server.addresses.1.primary', false));

        $this->actingAs($this->admin)
            ->from(route('admin.servers.show', $server))
            ->post(route('admin.servers.primary-address', $server), ['address' => $second])
            ->assertRedirect(route('admin.servers.show', $server))
            ->assertSessionHasNoErrors();

        $this->assertSame([$second, $first], $this->addresses($server));

        $this->actingAs($this->admin)
            ->post(route('admin.servers.primary-address', $server), ['address' => '198.51.100.5'])
            ->assertSessionHasErrors('address');
        $this->actingAs($this->admin)
            ->post(route('admin.servers.primary-address', $server), [])
            ->assertSessionHasErrors('ip_version');
    }
}
