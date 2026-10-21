<?php

namespace Tests\Feature\Networking;

use App\Domain\Infrastructure\DriftDetector;
use App\Domain\Infrastructure\FirewallCheck;
use App\Domain\Infrastructure\FirewallChecker;
use App\Enums\DriftType;
use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerDrift;
use App\Models\User;
use App\Provisioning\AddressChanger;
use App\Provisioning\ServerCreator;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\QemuResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * The VM firewall against ARP, IP, MAC, router advertisement and DHCP
 * spoofing, how the sync notices changes made in Proxmox, and how
 * administrators turn it on again (docs/adr/0033).
 */
class AntiSpoofingTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order());
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function vm(): array
    {
        return $this->proxmox->vms[$this->server->vmid];
    }

    private function sync(): void
    {
        $guests = app(ProxmoxClientFactory::class)->forCluster($this->cluster)->cluster()->guests();

        app(DriftDetector::class)->detect($this->cluster, $guests, app(FirewallChecker::class)->check($this->cluster, $guests));
    }

    private function firewallDrift(): ?ServerDrift
    {
        return ServerDrift::query()->where('type', DriftType::FirewallMismatch)->whereNull('resolved_at')->first();
    }

    /**
     * @return list<string>
     */
    private function ruleComments(): array
    {
        return array_map(fn (array $rule) => (string) $rule['comment'], $this->vm()['rules']);
    }

    public function test_a_new_server_gets_every_filter_and_the_dhcp_server_rules()
    {
        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status);
        $firewall = $this->vm()['firewall'];

        foreach (QemuResource::ANTI_SPOOFING_OPTIONS as $option => $value) {
            $this->assertEquals($value, $firewall[$option], $option);
        }

        $this->assertSame('ACCEPT', $firewall['policy_in']);
        // The isolation rules (docs/adr/0034) above the DHCP server rules.
        $this->assertSame([...array_column(QemuResource::ISOLATION_RULES, 'comment'), 'Proxmotion: no DHCPv6 server', 'Proxmotion: no DHCP server'], $this->ruleComments());
        $this->assertSame(['out', 'DROP', 'udp', '67', '68'], [
            $this->vm()['rules'][6]['type'], $this->vm()['rules'][6]['action'], $this->vm()['rules'][6]['proto'], $this->vm()['rules'][6]['sport'], $this->vm()['rules'][6]['dport'],
        ]);
        $this->assertTrue(QemuResource::interfaceFirewalled($this->vm()['config']['net0']));
    }

    public function test_applying_again_adds_no_second_dhcp_server_rule()
    {
        app(AddressChanger::class)->reapply($this->server->refresh(), $this->admin, null);

        $this->assertCount(2 + count(QemuResource::ISOLATION_RULES), $this->vm()['rules']);
    }

    public function test_a_freshly_provisioned_server_has_no_firewall_drift()
    {
        $this->sync();

        $this->assertNull($this->firewallDrift());
        $this->assertNotNull($this->server->refresh()->firewall_checked_at);
    }

    public function test_a_firewall_changed_in_proxmox_is_reported_and_restored()
    {
        $vmid = $this->server->vmid;
        $this->proxmox->vms[$vmid]['firewall']['enable'] = 0;
        $this->proxmox->vms[$vmid]['firewall']['radv'] = 1;
        $this->proxmox->vms[$vmid]['config']['net0'] = str_replace(',firewall=1', '', $this->proxmox->vms[$vmid]['config']['net0']);
        $this->proxmox->vms[$vmid]['rules'] = [];
        $this->proxmox->vms[$vmid]['ipset'][] = '203.0.113.6';

        $this->sync();

        $drift = $this->firewallDrift();
        $this->assertNotNull($drift);
        $this->assertSame($this->server->id, $drift->server_id);
        $this->assertSame(['firewall_off', 'radv_on', 'interface_firewall_off', 'dhcp_server_allowed', 'isolation_rules_missing', 'addresses_differ'], $drift->actual['problems']);
        $this->assertSame(['203.0.113.6'], $drift->actual['extra']);
        $this->assertSame([], $drift->actual['missing']);
        $this->assertSame($this->server->ipAddresses()->pluck('address')->all(), $drift->expected['addresses']);

        // The server list marks it.
        $this->actingAs($this->admin)->get(route('admin.servers.index'))
            ->assertInertia(fn ($page) => $page->where('servers.data.0.unprotected', true));

        $this->actingAs($this->admin)->post(route('admin.servers.enable-anti-spoofing', $this->server))->assertRedirect();

        $task = $this->server->tasks()->where('type', TaskType::EnableAntiSpoofing)->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertEquals(1, $this->vm()['firewall']['enable']);
        $this->assertEquals(0, $this->vm()['firewall']['radv']);
        $this->assertTrue(QemuResource::interfaceFirewalled($this->vm()['config']['net0']));
        $this->assertCount(2 + count(QemuResource::ISOLATION_RULES), $this->vm()['rules']);
        $this->assertNotContains('203.0.113.6', $this->vm()['ipset']);
        $this->assertTrue(AuditLog::query()->where('action', 'server.anti_spoofing_enabled')->exists());

        // Read back by the task itself: the page shows it at once, without waiting for a sync.
        $this->assertNull($this->firewallDrift());
        $this->assertSame('restored', $drift->refresh()->resolution);
        $this->actingAs($this->admin)->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn ($page) => $page
                ->where('drifts', [])
                ->whereNot('server.firewall_checked_at', null));
        $this->actingAs($this->admin)->get(route('admin.servers.index'))
            ->assertInertia(fn ($page) => $page->where('servers.data.0.unprotected', false));

        $this->sync();

        $this->assertNull($this->firewallDrift());
    }

    public function test_applying_again_closes_a_firewall_drift_at_once()
    {
        $this->proxmox->vms[$this->server->vmid]['firewall']['macfilter'] = 0;
        $this->sync();
        $this->assertNotNull($this->firewallDrift());

        $task = app(AddressChanger::class)->reapply($this->server->refresh(), $this->admin, null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertNull($this->firewallDrift());
    }

    public function test_a_restore_that_leaves_something_wrong_keeps_the_drift_up_to_date()
    {
        $this->proxmox->vms[$this->server->vmid]['firewall']['ipfilter'] = 0;
        $this->sync();
        $qemu = app(ProxmoxClientFactory::class)->forCluster($this->cluster)->qemu($this->server->node->name);

        // Proxmox kept the IP filter off, and net0 lost its firewall flag meanwhile.
        $this->proxmox->vms[$this->server->vmid]['config']['net0'] = str_replace(',firewall=1', '', $this->proxmox->vms[$this->server->vmid]['config']['net0']);
        app(FirewallChecker::class)->recheck($this->server->refresh(), $qemu);

        $this->assertSame(1, ServerDrift::query()->where('type', DriftType::FirewallMismatch)->count());
        $this->assertSame(['ipfilter_off', 'interface_firewall_off'], $this->firewallDrift()?->actual['problems']);
    }

    public function test_a_firewall_drift_stays_open_until_the_server_is_checked_again()
    {
        $this->proxmox->vms[$this->server->vmid]['firewall']['ipfilter'] = 0;
        $this->sync();
        $this->assertNotNull($this->firewallDrift());

        // A sync that did not look at this server's firewall (more servers than one batch).
        $guests = app(ProxmoxClientFactory::class)->forCluster($this->cluster)->cluster()->guests();
        app(DriftDetector::class)->detect($this->cluster, $guests, new FirewallCheck);

        $this->assertNotNull($this->firewallDrift());

        // Gone with the server.
        $this->server->forceFill(['status' => ServerStatus::Terminated])->save();
        app(DriftDetector::class)->detect($this->cluster, $guests, new FirewallCheck);

        $this->assertNull($this->firewallDrift());
    }

    public function test_the_servers_checked_longest_ago_are_checked_first()
    {
        $other = app(ServerCreator::class)->create($this->order(['hostname' => 'web2.example.com']));
        $this->server->forceFill(['firewall_checked_at' => now()->subHour()])->save();
        $other->forceFill(['firewall_checked_at' => now()->subDay()])->save();
        $guests = app(ProxmoxClientFactory::class)->forCluster($this->cluster)->cluster()->guests();

        $check = app(FirewallChecker::class)->check($this->cluster, $guests);

        $this->assertSame([$other->id, $this->server->id], $check->checkedServerIds);
    }

    public function test_adopted_servers_keep_their_firewall_policies()
    {
        // An adopted VM with a firewall of its own and no anti-spoofing.
        $this->server->forceFill(['origin' => ServerOrigin::Adopted])->save();
        $vmid = $this->server->vmid;
        $this->proxmox->vms[$vmid]['firewall'] = ['enable' => 1, 'policy_in' => 'DROP'];
        $this->proxmox->vms[$vmid]['rules'] = [['type' => 'in', 'action' => 'ACCEPT', 'proto' => 'tcp', 'dport' => '22', 'enable' => 1]];
        $this->proxmox->vms[$vmid]['ipset'] = null;

        $this->actingAs($this->admin)->post(route('admin.servers.enable-anti-spoofing', $this->server))->assertRedirect();

        $firewall = $this->vm()['firewall'];
        $this->assertSame('DROP', $firewall['policy_in']);
        $this->assertArrayNotHasKey('policy_out', $firewall);
        $this->assertEquals(1, $firewall['ipfilter']);
        $this->assertCount(3 + count(QemuResource::ISOLATION_RULES), $this->vm()['rules'], 'Its own rule stays.');
        $this->assertEqualsCanonicalizing($this->server->ipAddresses()->pluck('address')->all(), $this->vm()['ipset']);
        $this->assertNotNull($this->server->refresh()->firewall_checked_at, 'Checked: the page no longer offers the button.');
    }

    public function test_a_server_without_addresses_is_refused()
    {
        $this->server->ipAddresses()->update(['server_id' => null]);

        $this->actingAs($this->admin)->post(route('admin.servers.enable-anti-spoofing', $this->server))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        $this->assertFalse($this->server->tasks()->where('type', TaskType::EnableAntiSpoofing)->exists());
    }

    public function test_guests_cannot_enable_anti_spoofing()
    {
        $this->post(route('admin.servers.enable-anti-spoofing', $this->server))->assertRedirect(route('login'));
    }
}
