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
use App\Provisioning\AddressAddition;
use App\Provisioning\AddressChange;
use App\Provisioning\AddressChanger;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use App\Provisioning\Pipelines\AddressesPipeline;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Editing a server's addresses (docs/adr/0028): add, remove and choose the
 * primary in one change, or apply the addresses again; the firewall IP set
 * and the address guest script follow, cloud-init's ipconfig0 never changes.
 */
class AddressChangeTest extends TestCase
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

    private function server(int $ipv4 = 1, int $ipv6 = 0): Server
    {
        $spec = new ServerSpec(cpuCores: 1, memoryMib: 1024, diskMib: 10240, ipv4Count: $ipv4, ipv6Count: $ipv6);

        return app(ServerCreator::class)->create($this->order(['spec' => $spec]))->refresh();
    }

    private function change(Server $server, AddressChange $change): Task
    {
        return app(AddressChanger::class)->request($server, $change, $this->admin, '127.0.0.1')->refresh();
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

    private function assertRefused(Server $server, AddressChange $change, string $reason): void
    {
        $before = $this->addresses($server);

        try {
            $this->change($server, $change);
            $this->fail('The change was accepted.');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }

        $this->assertSame($before, $this->addresses($server), 'Nothing changes when a rule fails.');
        $this->assertSame(0, Task::query()->where('type', TaskType::ChangeAddresses)->count());
    }

    public function test_addresses_are_added_next_free_and_specific()
    {
        $server = $this->server();
        [$primary] = $this->addresses($server);
        $ipconfig = $this->proxmox->vms[$server->vmid]['config']['ipconfig0'];

        $task = $this->change($server, new AddressChange(add: [
            new AddressAddition(IpVersion::V4),
            new AddressAddition(IpVersion::V4, $this->ipv4Pool, '203.0.113.6'),
        ]));

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(TaskType::ChangeAddresses, $task->type);
        $addresses = $this->addresses($server);
        $this->assertCount(3, $addresses);
        $this->assertSame($primary, $addresses[0], 'The primary stays.');
        $this->assertContains('203.0.113.6', $addresses);
        $this->assertEqualsCanonicalizing($addresses, $this->proxmox->vms[$server->vmid]['ipset']);
        $this->assertSame($ipconfig, $this->proxmox->vms[$server->vmid]['config']['ipconfig0'], 'cloud-init settings are never changed.');

        $inputs = $this->guestInputs($server);
        $this->assertSame("{$primary}/29", $inputs['primary_ipv4']);
        $this->assertCount(2, $inputs['addresses']);

        $audit = AuditLog::query()->where('action', 'server.addresses_changed')->sole();
        $this->assertSame([$primary], $audit->properties['before']);
        $this->assertEqualsCanonicalizing($addresses, $audit->properties['after']);
        $this->assertContains('203.0.113.6', $audit->properties['added']);
        $this->assertSame([], $audit->properties['removed']);
    }

    public function test_a_chosen_additional_address_is_removed_and_released()
    {
        $server = $this->server(ipv4: 3);
        [$primary, $second, $third] = $this->addresses($server);
        IpAddress::query()->where('address', $second)->update(['ptr' => 'mail.example.com', 'ptr_synced_at' => now()]);

        $task = $this->change($server, new AddressChange(remove: [$second]));

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame([$primary, $third], $this->addresses($server), 'Not the newest one: the chosen one.');
        $this->assertEqualsCanonicalizing([$primary, $third], $this->proxmox->vms[$server->vmid]['ipset']);

        $released = IpAddress::query()->where('address', $second)->sole();
        $this->assertNull($released->server_id);
        $this->assertNull($released->ptr, 'The PTR of the released address is removed.');
        $this->assertSame(["{$third}/29"], $this->guestInputs($server)['addresses']);
    }

    public function test_the_primary_is_replaced_and_removed_in_one_change()
    {
        $server = $this->server(ipv4: 2);
        [$old, $second] = $this->addresses($server);

        $task = $this->change($server, new AddressChange(
            add: [new AddressAddition(IpVersion::V4, address: '203.0.113.6')],
            remove: [$old],
            primary: [4 => '203.0.113.6'],
        ));

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(['203.0.113.6', $second], $this->addresses($server));
        $this->assertTrue(IpAddress::query()->where('address', '203.0.113.6')->sole()->is_primary);
        $this->assertNull(IpAddress::query()->where('address', $old)->sole()->server_id);

        $inputs = $this->guestInputs($server);
        $this->assertSame('203.0.113.6/29', $inputs['primary_ipv4']);
        $this->assertSame("{$old}/29", $inputs['cloudinit_ipv4'], 'The script removes the old one inside the guest.');

        $audit = AuditLog::query()->where('action', 'server.addresses_changed')->sole();
        $this->assertSame(['before' => $old, 'after' => '203.0.113.6'], $audit->properties['primary']['IPv4']);
    }

    public function test_the_primary_is_not_removed_without_a_new_one()
    {
        $server = $this->server(ipv4: 2);
        [$primary] = $this->addresses($server);

        $this->assertRefused($server, new AddressChange(remove: [$primary]), 'Choose a new primary address');
    }

    public function test_the_last_address_of_a_version_stays()
    {
        $server = $this->server();
        [$primary] = $this->addresses($server);

        $this->assertRefused($server, new AddressChange(remove: [$primary]), 'keeps at least one');
    }

    public function test_unusable_changes_are_refused()
    {
        $server = $this->server(ipv4: 2);
        [$primary] = $this->addresses($server);
        [$taken] = $this->addresses($this->server());
        $other = IpPool::factory()->for($this->location)->create(['network' => '198.51.100.0/29', 'gateway' => '198.51.100.1', 'bridge' => 'vmbr9']);
        app(GenerateIpAddresses::class)->handle($other);

        $this->assertRefused($server, new AddressChange, 'Nothing to change');
        $this->assertRefused($server, new AddressChange(remove: [$taken]), 'not an address of this server');
        $this->assertRefused($server, new AddressChange(add: [new AddressAddition(IpVersion::V4, address: $taken)]), 'not a free address');
        $this->assertRefused($server, new AddressChange(add: [new AddressAddition(IpVersion::V4, address: '198.51.100.3')]), 'not a free address');
        $this->assertRefused($server, new AddressChange(add: [new AddressAddition(IpVersion::V4, $other)]), 'cannot be used for this server');
        $this->assertRefused($server, new AddressChange(primary: [4 => $taken]), 'not an address the server keeps');
        $this->assertRefused($server, new AddressChange(primary: [4 => $primary], add: [new AddressAddition(IpVersion::V4, address: $primary)]), 'not a free address');
    }

    public function test_addresses_with_their_own_mac_are_refused()
    {
        $server = $this->server();
        IpAddress::query()->where('address', '203.0.113.6')->update(['mac_address' => '02:00:00:00:00:06']);

        $this->assertRefused($server, new AddressChange(add: [new AddressAddition(IpVersion::V4, address: '203.0.113.6')]), 'needs its own MAC address');
    }

    public function test_only_idle_active_servers_are_changed()
    {
        $server = $this->server();
        app(TaskRunner::class)->start($server, TaskType::Shutdown, $this->admin, input: null);
        $server->tasks()->latest('id')->first()?->forceFill(['status' => TaskStatus::Running])->save();

        try {
            $this->change($server, new AddressChange(add: [new AddressAddition(IpVersion::V4)]));
            $this->fail('Accepted while a task runs.');
        } catch (TaskAlreadyRunning) {
        }

        $this->assertCount(1, $this->addresses($server), 'The added address is given back.');

        $server->tasks()->update(['status' => TaskStatus::Succeeded]);
        $server->forceFill(['status' => 'suspended'])->save();
        $this->expectException(ProvisioningException::class);
        $this->change($server, new AddressChange(add: [new AddressAddition(IpVersion::V4)]));
    }

    public function test_a_first_ipv6_address_becomes_the_primary()
    {
        $this->addIpv6Pool();
        $server = $this->server();

        $task = $this->change($server, new AddressChange(add: [new AddressAddition(IpVersion::V6)]));

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $ipv6 = $server->ipAddresses()->whereHas('pool', fn ($query) => $query->where('version', IpVersion::V6))->sole();
        $this->assertTrue($ipv6->is_primary);
        $this->assertSame("{$ipv6->address}/64", $this->guestInputs($server)['primary_ipv6']);
    }

    public function test_the_chosen_primary_is_flagged_so_an_older_address_never_takes_its_place()
    {
        $spec = new ServerSpec(cpuCores: 1, memoryMib: 1024, diskMib: 10240, ipv4Count: 1, ipv6Count: 0);
        $server = app(ServerCreator::class)->create($this->order(['spec' => $spec, 'primaryIpv4' => '203.0.113.5']))->refresh();
        // Servers from before ADR 0011 have no flag: the oldest address is their primary.
        $server->ipAddresses()->update(['is_primary' => false]);

        $this->change($server, new AddressChange(add: [new AddressAddition(IpVersion::V4, address: '203.0.113.2')]));

        $this->assertSame(['203.0.113.5', '203.0.113.2'], $this->addresses($server), 'The added address is older, yet not the primary.');
        $this->assertTrue(IpAddress::query()->where('address', '203.0.113.5')->sole()->is_primary);
    }

    public function test_addresses_are_applied_again()
    {
        $server = $this->server(ipv4: 2);
        $addresses = $this->addresses($server);
        $this->proxmox->vms[$server->vmid]['ipset'][] = '198.51.100.99';
        $this->proxmox->vms[$server->vmid]['ipset'] = array_values(array_diff($this->proxmox->vms[$server->vmid]['ipset'], [$addresses[1]]));
        unset($this->proxmox->guestFiles[$server->vmid]['/etc/proxmotion/addresses.json']);

        $task = app(AddressChanger::class)->reapply($server, $this->admin, null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame($addresses, $this->addresses($server), 'Nothing changes in the panel.');
        $this->assertEqualsCanonicalizing($addresses, $this->proxmox->vms[$server->vmid]['ipset'], 'Exactly the server\'s addresses.');
        $this->assertSame(["{$addresses[1]}/29"], $this->guestInputs($server)['addresses'], 'The guest script ran again.');
        $this->assertSame($addresses, AuditLog::query()->where('action', 'server.addresses_reapplied')->sole()->properties['addresses']);
    }

    public function test_the_task_can_run_again()
    {
        $server = $this->server(ipv4: 3);
        [$primary, $second, $third] = $this->addresses($server);
        $task = $this->change($server, new AddressChange(remove: [$second]));

        app(AddressesPipeline::class)->run($task);

        $this->assertSame([$primary, $third], $this->addresses($server));
        $this->assertEqualsCanonicalizing([$primary, $third], $this->proxmox->vms[$server->vmid]['ipset']);
    }

    public function test_admins_edit_the_addresses_from_the_server_page()
    {
        $server = $this->server(ipv4: 2);
        [$primary, $second] = $this->addresses($server);

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $server))
            ->assertInertia(fn (Assert $page) => $page
                ->has('addressPools', 1)
                ->where('addressPools.0.value', $this->ipv4Pool->uuid)
                ->where('addressPools.0.version', 4)
                ->where('addressPools.0.free', 3));

        $this->actingAs($this->admin)
            ->from(route('admin.servers.show', $server))
            ->post(route('admin.servers.addresses', $server), [
                'add' => [['ip_version' => 4, 'pool' => $this->ipv4Pool->uuid, 'address' => '203.0.113.6']],
                'remove' => [$second],
            ])
            ->assertRedirect(route('admin.servers.show', $server))
            ->assertSessionHasNoErrors();

        $this->assertSame([$primary, '203.0.113.6'], $this->addresses($server));

        $this->actingAs($this->admin)
            ->post(route('admin.servers.addresses', $server), ['remove' => [$primary]])
            ->assertSessionHasErrors('addresses');
        $this->actingAs($this->admin)
            ->post(route('admin.servers.addresses', $server), ['add' => [['ip_version' => 6, 'address' => '203.0.113.5']]])
            ->assertSessionHasErrors('add.0.address');
        $this->actingAs($this->admin)
            ->post(route('admin.servers.addresses', $server), ['add' => [['ip_version' => 4, 'pool' => 'nope']]])
            ->assertSessionHasErrors('add.0.pool');

        $this->actingAs($this->admin)
            ->post(route('admin.servers.reapply-addresses', $server))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::query()->where('action', 'server.addresses_reapplied')->count());
    }

    public function test_guests_cannot_edit_addresses()
    {
        $server = $this->server();

        $this->post(route('admin.servers.addresses', $server), ['add' => [['ip_version' => 4]]])->assertRedirect(route('login'));
        $this->post(route('admin.servers.reapply-addresses', $server))->assertRedirect(route('login'));
        $this->assertCount(1, $this->addresses($server));
    }
}
