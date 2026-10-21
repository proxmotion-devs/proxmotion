<?php

namespace Tests\Feature\Networking;

use App\Domain\Infrastructure\DriftDetector;
use App\Domain\Infrastructure\FirewallChecker;
use App\Domain\Networking\GenerateIpAddresses;
use App\Enums\DriftType;
use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Enums\ServerOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\ServerDrift;
use App\Models\User;
use App\Provisioning\AddressAddition;
use App\Provisioning\AddressChange;
use App\Provisioning\AddressChanger;
use App\Provisioning\ServerCreator;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\QemuResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Servers kept away from their neighbours and from the protected networks
 * through VM firewall IP sets and rules (docs/adr/0034).
 */
class GuestIsolationTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        // A node address the sync learned from its bridge, a management network and the panel's address.
        $this->node->bridges()->update(['cidr' => '198.51.100.11/24']);
        $this->location->update(['protected_networks' => ['192.0.2.0/24']]);
        config(['proxmotion.protected_networks' => ['198.51.100.200', 'not-a-network']]);

        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    private function create(string $hostname = 'web1.example.com'): Server
    {
        return app(ServerCreator::class)->create($this->order(['hostname' => $hostname]));
    }

    /**
     * @return array<string, mixed>
     */
    private function vm(Server $server): array
    {
        return $this->proxmox->vms[$server->vmid];
    }

    /**
     * @return list<string>
     */
    private function ruleComments(Server $server): array
    {
        return array_map(fn (array $rule) => (string) ($rule['comment'] ?? ''), $this->vm($server)['rules']);
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

    public function test_a_new_server_gets_the_isolation_sets_and_rules_on_top()
    {
        $server = $this->create();

        $this->assertSame(['203.0.113.0/29'], $this->vm($server)['ipsets'][QemuResource::ISOLATED_SET]);
        $this->assertSame(['203.0.113.1'], $this->vm($server)['ipsets'][QemuResource::GATEWAYS_SET]);
        $this->assertSame(['198.51.100.11', '192.0.2.0/24', '198.51.100.200'], $this->vm($server)['ipsets'][QemuResource::PROTECTED_SET]);

        $comments = array_column(QemuResource::ISOLATION_RULES, 'comment');
        $this->assertSame($comments, array_slice($this->ruleComments($server), 0, count($comments)));
        $this->assertSame(['out', 'DROP', '+pm-protected'], [$this->vm($server)['rules'][0]['type'], $this->vm($server)['rules'][0]['action'], $this->vm($server)['rules'][0]['dest']]);
        $this->assertSame(['in', 'DROP', '+pm-isolated'], [$this->vm($server)['rules'][4]['type'], $this->vm($server)['rules'][4]['action'], $this->vm($server)['rules'][4]['source']]);
    }

    public function test_a_pool_that_does_not_isolate_only_keeps_the_protected_networks()
    {
        $this->ipv4Pool->update(['isolate_guests' => false]);

        $server = $this->create();

        // No rule refers to an empty IP set.
        $this->assertSame([QemuResource::PROTECTED_SET], array_keys($this->vm($server)['ipsets']));
        $this->assertSame(['Proxmotion: no connections to protected networks', 'Proxmotion: no DHCPv6 server', 'Proxmotion: no DHCP server'], $this->ruleComments($server));

        $this->sync();

        $this->assertNull($this->firewallDrift());
    }

    public function test_turning_isolation_off_removes_the_neighbour_rules_and_sets()
    {
        $server = $this->create();
        $this->ipv4Pool->update(['isolate_guests' => false]);

        // Until an administrator applies it, the server is reported.
        $this->sync();
        $this->assertSame(['isolation_rules_missing'], $this->firewallDrift()?->actual['problems']);

        $this->actingAs($this->admin)->post(route('admin.servers.enable-anti-spoofing', $server))->assertRedirect();

        $task = $server->tasks()->where('type', TaskType::EnableAntiSpoofing)->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame([QemuResource::PROTECTED_SET], array_keys($this->vm($server)['ipsets']));
        $this->assertSame(['Proxmotion: no connections to protected networks', 'Proxmotion: no DHCPv6 server', 'Proxmotion: no DHCP server'], $this->ruleComments($server));
        $this->assertNull($this->firewallDrift());

        // And back on.
        $this->ipv4Pool->update(['isolate_guests' => true]);
        app(AddressChanger::class)->reapply($server->refresh(), $this->admin, null);

        $this->assertTrue(QemuResource::isolationRulesInPlace($this->vm($server)['rules']));
        $this->assertSame(['203.0.113.0/29'], $this->vm($server)['ipsets'][QemuResource::ISOLATED_SET]);
    }

    public function test_without_isolation_or_protected_networks_the_firewall_gets_no_isolation_rules()
    {
        $this->ipv4Pool->update(['isolate_guests' => false]);
        $this->node->bridges()->update(['cidr' => null]);
        $this->location->update(['protected_networks' => null]);
        config(['proxmotion.protected_networks' => []]);

        $server = $this->create();

        $this->assertSame([], $this->vm($server)['ipsets']);
        $this->assertSame(['Proxmotion: no DHCPv6 server', 'Proxmotion: no DHCP server'], $this->ruleComments($server));
    }

    public function test_applying_again_writes_nothing_when_everything_is_right()
    {
        $server = $this->create();
        $qemu = app(ProxmoxClientFactory::class)->forCluster($this->cluster)->qemu('pve1');
        $before = count($this->proxmox->writes);

        $qemu->applyIsolation($server->vmid, [
            QemuResource::ISOLATED_SET => ['203.0.113.0/29'],
            QemuResource::GATEWAYS_SET => ['203.0.113.1'],
            QemuResource::PROTECTED_SET => ['198.51.100.11', '192.0.2.0/24', '198.51.100.200'],
        ]);

        $this->assertSame($before, count($this->proxmox->writes));
    }

    public function test_a_freshly_provisioned_server_has_no_firewall_drift()
    {
        $this->create();

        $this->sync();

        $this->assertNull($this->firewallDrift());
    }

    public function test_rules_out_of_order_are_reported_and_restored_with_other_rules_kept()
    {
        $server = $this->create();
        $vmid = $server->vmid;
        // Someone moved the neighbour rule above the gateway rule and added a rule of their own.
        $rules = $this->proxmox->vms[$vmid]['rules'];
        [$rules[1], $rules[3]] = [$rules[3], $rules[1]];
        $this->proxmox->vms[$vmid]['rules'] = [['type' => 'in', 'action' => 'ACCEPT', 'proto' => 'tcp', 'dport' => '22', 'enable' => 1, 'comment' => 'ssh'], ...$rules];

        $this->sync();

        $this->assertSame(['isolation_rules_missing'], $this->firewallDrift()?->actual['problems']);

        $this->actingAs($this->admin)->post(route('admin.servers.enable-anti-spoofing', $server))->assertRedirect();

        $task = $server->tasks()->where('type', TaskType::EnableAntiSpoofing)->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $comments = array_column(QemuResource::ISOLATION_RULES, 'comment');
        $this->assertSame([...$comments, 'ssh', 'Proxmotion: no DHCPv6 server', 'Proxmotion: no DHCP server'], $this->ruleComments($server));
        $this->assertNull($this->firewallDrift());
    }

    public function test_a_disabled_rule_counts_as_missing()
    {
        $server = $this->create();
        $this->proxmox->vms[$server->vmid]['rules'][4]['enable'] = 0;

        $this->sync();

        $this->assertSame(['isolation_rules_missing'], $this->firewallDrift()?->actual['problems']);
    }

    public function test_changed_protected_networks_are_reported_and_applied_by_enable_anti_spoofing()
    {
        $server = $this->create();
        $this->location->update(['protected_networks' => ['192.0.2.0/25']]);

        $this->sync();

        $this->assertSame(['isolation_sets_differ'], $this->firewallDrift()?->actual['problems']);

        $this->actingAs($this->admin)->post(route('admin.servers.enable-anti-spoofing', $server))->assertRedirect();

        $this->assertEqualsCanonicalizing(['198.51.100.11', '192.0.2.0/25', '198.51.100.200'], $this->vm($server)['ipsets'][QemuResource::PROTECTED_SET]);
        $this->assertNull($this->firewallDrift());
    }

    public function test_a_missing_ip_set_is_reported()
    {
        $server = $this->create();
        unset($this->proxmox->vms[$server->vmid]['ipsets'][QemuResource::GATEWAYS_SET]);

        $this->sync();

        $this->assertSame(['isolation_sets_differ'], $this->firewallDrift()?->actual['problems']);
    }

    public function test_the_sets_follow_an_address_from_another_pool()
    {
        $server = $this->create();
        $other = IpPool::factory()->for($this->location)->create([
            'network' => '203.0.113.64/28',
            'gateway' => '203.0.113.65',
            'bridge' => 'vmbr0',
            'network_model' => NetworkModel::Bridged,
            'vlan_tag' => null,
        ]);
        app(GenerateIpAddresses::class)->handle($other);

        $task = app(AddressChanger::class)->request($server->refresh(), new AddressChange(add: [new AddressAddition(IpVersion::V4, $other, '203.0.113.70')]), $this->admin, null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(['203.0.113.0/29', '203.0.113.64/28'], $this->vm($server)['ipsets'][QemuResource::ISOLATED_SET]);
        $this->assertSame(['203.0.113.1', '203.0.113.65'], $this->vm($server)['ipsets'][QemuResource::GATEWAYS_SET]);
    }

    public function test_adopted_servers_get_isolation_only_when_an_administrator_asks()
    {
        $server = $this->create();
        $server->forceFill(['origin' => ServerOrigin::Adopted])->save();
        $this->proxmox->vms[$server->vmid]['rules'] = [];
        $this->proxmox->vms[$server->vmid]['ipsets'] = [];

        // An ordinary address change leaves an adopted VM's rules alone.
        $task = app(AddressChanger::class)->request($server->refresh(), new AddressChange(add: [new AddressAddition(IpVersion::V4)]), $this->admin, null)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame([], $this->vm($server)['ipsets']);

        $this->actingAs($this->admin)->post(route('admin.servers.enable-anti-spoofing', $server))->assertRedirect();

        $this->assertTrue(QemuResource::isolationRulesInPlace($this->vm($server)['rules']));
        $this->assertSame(['203.0.113.0/29'], $this->vm($server)['ipsets'][QemuResource::ISOLATED_SET]);
    }
}
