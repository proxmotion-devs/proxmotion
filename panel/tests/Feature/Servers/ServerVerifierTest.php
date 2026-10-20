<?php

namespace Tests\Feature\Servers;

use App\Domain\Servers\ServerVerifier;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * After a create or reinstall, and on Check again, the server is checked from
 * inside (docs/adr/0032 §6): addresses on its interface, gateways, DNS,
 * hostname and disk. A failed check only warns.
 */
class ServerVerifierTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private const VMID = 100;

    private FakeProxmoxCluster $proxmox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
    }

    /**
     * @return array<string, string> status per check key (gateways by address)
     */
    private function statuses(Server $server): array
    {
        $statuses = [];

        foreach ($server->verification['checks'] ?? [] as $check) {
            $statuses[$check['key'] === 'gateway' ? 'gateway '.$check['params']['address'] : $check['key']] = $check['status'];
        }

        return $statuses;
    }

    private function verifyStep(Task $task): array
    {
        return collect($task->steps)->firstWhere('key', 'verify');
    }

    public function test_a_new_server_is_checked_from_inside()
    {
        $this->addIpv6Pool();
        $this->package->forceFill(['ipv6_count' => 1])->save();

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame([
            'addresses' => 'passed',
            'gateway 203.0.113.1' => 'passed',
            'gateway 2001:db8:100::1' => 'passed',
            'dns' => 'passed',
            'hostname' => 'passed',
            'disk' => 'passed',
        ], $this->statuses($server));
        $this->assertNull($this->verifyStep($server->tasks()->sole())['message']);

        // The gateways and the DNS name are passed as arguments of the fixed script, never inside it.
        $this->assertSame(
            [['sh', '-c', ServerVerifier::LINUX_SCRIPT, ServerVerifier::SCRIPT_NAME, '203.0.113.1', '2001:db8:100::1', 'example.com']],
            $this->proxmox->verifyCommands[self::VMID],
        );
    }

    public function test_failed_checks_only_warn()
    {
        $this->proxmox->guestInterfaces[self::VMID] = [['name' => 'eth0', 'hardware-address' => '', 'ip-addresses' => []]];
        $this->proxmox->verifyOutput[self::VMID] = "hostname=localhost\ndisk=2147483648\ngateway=203.0.113.1 fail\ndns=fail\n";

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame([
            'addresses' => 'failed',
            'gateway 203.0.113.1' => 'failed',
            'dns' => 'failed',
            'hostname' => 'failed',
            'disk' => 'failed',
        ], $this->statuses($server));
        $this->assertSame(
            'Failed checks: addresses, gateway 203.0.113.1, dns example.com, hostname, disk. See the checks on the server page.',
            $this->verifyStep($task)['message'],
        );

        $checks = collect($server->verification['checks'])->keyBy('key');
        $this->assertSame('No network interface has the MAC address :mac.', $checks['addresses']['detail']);
        $this->assertSame(['hostname' => 'web1.example.com', 'actual' => 'localhost'], $checks['hostname']['params']);
        $this->assertSame('2 GiB', $checks['disk']['params']['actual']);
    }

    public function test_an_address_missing_on_the_interface_is_named()
    {
        $server = app(ServerCreator::class)->create($this->order());
        $mac = strtolower((string) $server->refresh()->mac_address);
        $this->proxmox->guestInterfaces[self::VMID] = [['name' => 'ens18', 'hardware-address' => strtoupper($mac), 'ip-addresses' => [['ip-address' => 'fe80::1%2']]]];

        $checks = app(ServerVerifier::class)->verify($server);

        $this->assertSame('failed', $checks[0]['status']);
        $this->assertSame('Missing on :interface: :addresses.', $checks[0]['detail']);
        $this->assertSame(['interface' => 'ens18', 'addresses' => $server->ipAddresses()->first()->address], $checks[0]['params']);
    }

    public function test_an_agent_that_refuses_commands_skips_the_checks_it_needs()
    {
        $this->proxmox->agentBlocked[self::VMID] = ['exec', 'network-get-interfaces'];
        Sleep::fake();

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame(['addresses' => 'skipped', 'gateway' => 'skipped', 'dns' => 'skipped', 'hostname' => 'skipped', 'disk' => 'skipped'], array_map(fn ($status) => $status, collect($server->verification['checks'])->pluck('status', 'key')->all()));
        $this->assertNull($this->verifyStep($server->tasks()->sole())['message'], 'Skipped checks are not failures.');
    }

    public function test_an_agent_that_does_not_answer_leaves_a_note()
    {
        $this->proxmox->agentDown[self::VMID] = true;
        Sleep::fake(syncWithCarbon: true);

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertNull($server->verification);
        $this->assertStringStartsWith('The checks could not run: The QEMU guest agent of VM 100 does not answer.', $this->verifyStep($server->tasks()->sole())['message']);
    }

    public function test_the_dns_check_uses_the_configured_name_or_none()
    {
        config(['proxmotion.provisioning.verify_dns_name' => '']);
        $server = app(ServerCreator::class)->create($this->order());
        $this->assertArrayNotHasKey('dns', $this->statuses($server->refresh()));

        // Never anything that is not a plain host name.
        config(['proxmotion.provisioning.verify_dns_name' => "example.com'; rm -rf /"]);
        $this->assertNotContains('dns', array_column(app(ServerVerifier::class)->verify($server), 'key'));

        config(['proxmotion.provisioning.verify_dns_name' => 'Debian.ORG']);
        $checks = collect(app(ServerVerifier::class)->verify($server))->keyBy('key');
        $this->assertSame(['name' => 'debian.org'], $checks['dns']['params']);
    }

    public function test_windows_servers_are_checked_with_powershell()
    {
        $this->osTemplate->forceFill(['distribution' => 'windows'])->save();
        $this->proxmox->execHandler = fn () => [0, '', ''];

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $command = $this->proxmox->verifyCommands[self::VMID][0];
        $this->assertSame(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command'], array_slice($command, 0, 4));
        $this->assertStringContainsString("foreach (\$gateway in @('203.0.113.1'))", $command[4]);
        $this->assertStringContainsString("\$name = 'example.com'", $command[4]);
        $this->assertSame('passed', $this->statuses($server)['gateway 203.0.113.1']);
        $this->assertSame('passed', $this->statuses($server)['hostname']);
    }

    public function test_administrators_can_check_an_active_server_again()
    {
        $server = app(ServerCreator::class)->create($this->order());
        $admin = User::factory()->withTwoFactor()->create();
        $this->proxmox->verifyOutput[self::VMID] = "hostname=web1\ndisk=\ngateway=203.0.113.1 fail\ndns=ok\n";

        $this->actingAs($admin)
            ->from(route('admin.servers.show', $server))
            ->post(route('admin.servers.verify', $server))
            ->assertRedirect(route('admin.servers.show', $server));

        $task = $server->tasks()->latest('id')->first();
        $this->assertSame(TaskType::Verify, $task->type);
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(['addresses' => 'passed', 'gateway 203.0.113.1' => 'failed', 'dns' => 'passed', 'hostname' => 'passed', 'disk' => 'skipped'], $this->statuses($server->refresh()));

        $this->actingAs($admin)
            ->get(route('admin.servers.show', $server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('server.verification.checks.1.status', 'failed')
                ->where('tasks.0.type', 'verify'));

        // An agent that does not answer fails the check task.
        $this->proxmox->agentDown[self::VMID] = true;
        $this->actingAs($admin)->post(route('admin.servers.verify', $server));
        $this->assertSame(TaskStatus::Failed, $server->tasks()->latest('id')->first()->status);

        // Only active servers.
        $server->forceFill(['status' => ServerStatus::Suspended])->save();
        $count = $server->tasks()->count();
        $this->actingAs($admin)->post(route('admin.servers.verify', $server));
        $this->assertSame($count, $server->tasks()->count());
    }
}
