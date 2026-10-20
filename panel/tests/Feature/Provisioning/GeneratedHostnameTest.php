<?php

namespace Tests\Feature\Provisioning;

use App\Domain\Servers\ServerReinstaller;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\User;
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

        // The VM is named after the hostname, which cloud-init sets; notes and a tag carry the address.
        $config = $this->proxmox->vms[$server->vmid]['config'];
        $this->assertNull($server->vm_name);
        $this->assertSame('ip-203-0-113-2.example.com', $config['name']);
        $this->assertSame("Managed by Proxmotion. Server {$server->uuid}\n\nPrimary address: 203.0.113.2", $config['description']);
        $this->assertSame('203.0.113.2', $config['tags']);
        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertFalse($task->hasCompleted('guest_hostname'), 'cloud-init sets it from the VM name.');
        $this->assertArrayNotHasKey('/etc/proxmotion/hostname.vars', $this->proxmox->guestFiles[$server->vmid] ?? []);
    }

    public function test_a_reinstall_names_a_vm_named_after_its_address_after_the_hostname()
    {
        // Servers named from a template before 2026-10-20 have their address as VM name.
        $server = app(ServerCreator::class)->create($this->order(['hostname' => null]))->refresh();
        $server->forceFill(['vm_name' => '203.0.113.2'])->save();
        $this->proxmox->vms[$server->vmid]['config']['name'] = '203.0.113.2';

        $task = app(ServerReinstaller::class)->request($server, $this->osTemplate, [], User::factory()->create(), null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertNull($server->refresh()->vm_name);
        $this->assertSame('ip-203-0-113-2.example.com', $this->proxmox->vms[$server->vmid]['config']['name']);
        $this->assertFalse($task->hasCompleted('guest_hostname'));
    }

    public function test_a_vm_named_after_its_address_gets_the_hostname_inside()
    {
        $server = app(ServerCreator::class)->create($this->order(['hostname' => null]))->refresh();
        $server->forceFill(['vm_name' => '203.0.113.2'])->save();
        unset($this->proxmox->guestFiles[$server->vmid]);

        $task = app(TaskRunner::class)->start($server, TaskType::Reinstall)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame('203.0.113.2', $this->proxmox->vms[$server->vmid]['config']['name']);
        $this->assertStringContainsString("hostname=ip-203-0-113-2.example.com\n", $this->proxmox->guestFiles[$server->vmid]['/etc/proxmotion/hostname.vars']);
    }

    public function test_windows_takes_the_name_without_a_restart()
    {
        $this->osTemplate->forceFill(['distribution' => 'windows'])->save();

        $server = app(ServerCreator::class)->create($this->order(['hostname' => null]));

        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame('ip-203-0-113-2.example.com', $this->proxmox->vms[$server->vmid]['config']['name']);
        $this->assertArrayNotHasKey('C:\\ProgramData\\Proxmotion\\hostname.json', $this->proxmox->guestFiles[$server->vmid] ?? []);
        $this->assertFalse($task->hasCompleted('reboot'));
    }

    public function test_refused_tags_leave_a_note_and_the_server_is_built()
    {
        $this->proxmox->refuseTags = 'Permission check failed (/, Sys.Modify)';

        $server = app(ServerCreator::class)->create($this->order(['hostname' => null]));

        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertStringContainsString('Primary address: 203.0.113.2', $this->proxmox->vms[$server->vmid]['config']['description']);
        $this->assertArrayNotHasKey('tags', $this->proxmox->vms[$server->vmid]['config']);
        $this->assertSame('The address tags were not set: Permission check failed (/, Sys.Modify)', collect($task->steps)->firstWhere('key', 'configure')['message']);
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
        $this->assertSame("ip-{$label}.example.com", $this->proxmox->vms[$server->vmid]['config']['name']);
        // A tag has no colons.
        $this->assertSame($label, $this->proxmox->vms[$server->vmid]['config']['tags']);
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
