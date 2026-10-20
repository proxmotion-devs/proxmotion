<?php

namespace Tests\Feature\Provisioning;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\IpAddress;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\HostnameGenerator;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Hostnames generated from the location's template for servers ordered
 * without one, and the PTRs of their primary addresses (docs/adr/0031).
 */
class GeneratedHostnameTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->location->update(['hostname_template' => 'ip-{ip}.example.com']);
    }

    public function test_the_primary_ipv4_address_names_the_server_and_gets_the_name_as_ptr()
    {
        $server = app(ServerCreator::class)->create($this->order(['hostname' => null]));

        $this->assertSame('ip-203-0-113-2.example.com', $server->refresh()->hostname);
        $this->assertSame('ip-203-0-113-2.example.com', IpAddress::query()->where('address', '203.0.113.2')->value('ptr'));

        // The VM is named after the address; the hostname is set inside the guest.
        $this->assertSame('203.0.113.2', $server->vm_name);
        $this->assertSame('203.0.113.2', $this->proxmox->vms[$server->vmid]['config']['name']);
        $this->assertStringContainsString("hostname=ip-203-0-113-2.example.com\n", $this->proxmox->guestFiles[$server->vmid]['/etc/proxmotion/hostname.vars']);
        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertTrue($task->hasCompleted('guest_hostname'));
    }

    public function test_a_reinstall_keeps_the_vm_name_and_sets_the_hostname_inside_again()
    {
        $server = app(ServerCreator::class)->create($this->order(['hostname' => null]))->refresh();
        unset($this->proxmox->guestFiles[$server->vmid]);

        $task = app(TaskRunner::class)->start($server, TaskType::Reinstall)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame('203.0.113.2', $this->proxmox->vms[$server->vmid]['config']['name']);
        $this->assertStringContainsString("hostname=ip-203-0-113-2.example.com\n", $this->proxmox->guestFiles[$server->vmid]['/etc/proxmotion/hostname.vars']);
    }

    public function test_windows_is_restarted_once_to_take_the_name()
    {
        $this->osTemplate->forceFill(['distribution' => 'windows'])->save();

        $server = app(ServerCreator::class)->create($this->order(['hostname' => null]));

        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertArrayHasKey('C:\\ProgramData\\Proxmotion\\hostname.json', $this->proxmox->guestFiles[$server->vmid]);
        $this->assertTrue($task->hasCompleted('reboot'));
        $this->assertFalse($server->refresh()->reboot_required);
    }

    public function test_only_the_primary_address_of_each_version_gets_a_ptr()
    {
        $this->addIpv6Pool();
        $this->package->update(['ipv4_count' => 2, 'ipv6_count' => 2]);

        $server = app(ServerCreator::class)->create($this->order(['hostname' => null, 'spec' => ServerSpec::fromPackage($this->package->refresh())]));

        $addresses = $server->ipAddresses()->with('pool')->get();
        $this->assertCount(4, $addresses);
        $named = $addresses->whereNotNull('ptr')->values();
        $this->assertSame(['203.0.113.2', $addresses->firstWhere('pool.version.value', 6)?->address], $named->pluck('address')->all());
        $this->assertSame(['ip-203-0-113-2.example.com'], $named->pluck('ptr')->unique()->values()->all());
    }

    public function test_a_server_without_ipv4_is_named_from_its_ipv6_address()
    {
        $this->addIpv6Pool();
        $this->package->update(['ipv4_count' => 0, 'ipv6_count' => 1]);

        $server = app(ServerCreator::class)->create($this->order(['hostname' => null, 'spec' => ServerSpec::fromPackage($this->package->refresh())]));

        $address = $server->ipAddresses()->sole()->address;
        $label = implode('-', str_split(bin2hex((string) inet_pton($address)), 4));
        $this->assertStringStartsWith('2001-0db8-0100-0000-', $label);
        $this->assertSame("ip-{$label}.example.com", $server->refresh()->hostname);
        // A VM name has no colons.
        $this->assertSame($label, $this->proxmox->vms[$server->vmid]['config']['name']);
    }

    public function test_vmid_and_location_placeholders()
    {
        $this->location->update(['hostname_template' => 'vps{vmid}.{location}.example.com']);

        $server = app(ServerCreator::class)->create($this->order(['hostname' => null]));

        $this->assertSame('vps100.ist1.example.com', $server->refresh()->hostname);
    }

    public function test_a_given_hostname_wins_and_sets_no_ptr()
    {
        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame('web1.example.com', $server->refresh()->hostname);
        $this->assertSame(0, IpAddress::query()->whereNotNull('ptr')->count());
        $this->assertNull($server->vm_name);
        $this->assertSame('web1.example.com', $this->proxmox->vms[$server->vmid]['config']['name']);
        $this->assertFalse($server->tasks()->sole()->hasCompleted('guest_hostname'), 'cloud-init sets it from the VM name.');
    }

    public function test_without_a_template_a_hostname_is_required()
    {
        $this->location->update(['hostname_template' => null]);

        try {
            app(ServerCreator::class)->create($this->order(['hostname' => null]));
            $this->fail('A server without a hostname was created.');
        } catch (ProvisioningException $e) {
            $this->assertSame("Enter a hostname: the location {$this->location->name} has no hostname template.", $e->translatedMessage());
        }

        $this->assertSame(0, Server::query()->count());
        $this->assertSame(0, IpAddress::query()->whereNotNull('server_id')->count());
    }

    public function test_templates_are_checked_with_the_longest_values()
    {
        $this->assertNull(HostnameGenerator::problem('ip-{ip}.example.com', 'ist1'));
        $this->assertNull(HostnameGenerator::problem('{vmid}.{location}.example.com', 'ist1'));
        $this->assertNotNull(HostnameGenerator::problem('{location}.example.com', 'ist1'));
        $this->assertNotNull(HostnameGenerator::problem('{ip}.{name}.example.com', 'ist1'));
        $this->assertNotNull(HostnameGenerator::problem('{ip}..example.com', 'ist1'));
        $this->assertSame('ip-203-0-113-5.example.com', HostnameGenerator::example('ip-{ip}.example.com', 'ist1'));
        $this->assertSame('2001-0db8-0000-0000-0000-0000-0000-0005.example.com', HostnameGenerator::render('{ip}.example.com', '2001:db8::5', 100, 'ist1'));
    }
}
