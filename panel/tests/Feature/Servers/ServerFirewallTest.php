<?php

namespace Tests\Feature\Servers;

use App\Domain\Apps\AppInstaller;
use App\Domain\Firewall\FirewallRule;
use App\Domain\Firewall\ServerFirewalls;
use App\Domain\Infrastructure\DriftDetector;
use App\Domain\Infrastructure\FirewallChecker;
use App\Enums\AppInstallationStatus;
use App\Enums\DriftType;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AppRecipe;
use App\Models\FirewallRuleset;
use App\Models\OsTemplate;
use App\Models\ProxmoxTemplate;
use App\Models\Server;
use App\Models\ServerDrift;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\ServerCreator;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Concerns\OffersApps;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * A server's firewall around its lifecycle (docs/adr/0035): rule sets of
 * packages, the default rule across reinstalls, app ports, the firewall
 * check, and the admin page.
 */
class ServerFirewallTest extends TestCase
{
    use BuildsInfrastructure, OffersApps, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake(syncWithCarbon: true);
        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->addTemplate(9001)->install();
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    private function create(): Server
    {
        return app(ServerCreator::class)->create($this->order())->refresh();
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

    private function turnOn(Server $server, array $rules = []): void
    {
        $task = app(ServerFirewalls::class)->change($server, 'drop', $rules, $this->admin, null)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
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

    public function test_a_package_rule_set_starts_new_servers_with_the_firewall_on()
    {
        $ruleset = FirewallRuleset::create(['name' => 'Web', 'is_active' => true, 'rules' => [
            ['action' => 'accept', 'protocol' => 'tcp', 'port' => '443', 'source' => null, 'comment' => 'HTTPS'],
        ]]);
        $this->package->update(['firewall_ruleset_id' => $ruleset->id]);

        $server = $this->create();

        $this->assertSame('drop', $server->firewall_policy);
        $this->assertSame(['443'], $server->firewallRules()->pluck('port')->all());
        $this->assertSame('DROP', $this->vm($server)['firewall']['policy_in']);
        $this->assertContains('Proxmotion rule: HTTPS', $this->ruleComments($server));

        $this->sync();
        $this->assertNull($this->firewallDrift());
    }

    public function test_an_inactive_rule_set_is_not_applied_to_new_servers()
    {
        $ruleset = FirewallRuleset::create(['name' => 'Old', 'is_active' => false, 'rules' => []]);
        $this->package->update(['firewall_ruleset_id' => $ruleset->id]);

        $this->assertSame('accept', $this->create()->firewall_policy);
    }

    public function test_a_reinstall_onto_windows_swaps_the_untouched_ssh_rule_for_rdp()
    {
        $server = $this->create();
        $this->turnOn($server);
        $this->assertSame(['22'], $server->firewallRules()->pluck('port')->all());

        $this->reinstallOntoWindows($server);

        $this->assertSame(['3389'], $server->firewallRules()->pluck('port')->all());
        $this->assertContains('Proxmotion rule: RDP', $this->ruleComments($server));
    }

    public function test_a_reinstall_keeps_rules_someone_chose_and_says_so()
    {
        $server = $this->create();
        $this->turnOn($server, [new FirewallRule('accept', 'tcp', '443')]);

        $task = $this->reinstallOntoWindows($server);

        $this->assertSame(['443'], $server->firewallRules()->pluck('port')->all());
        $step = collect($task->steps)->firstWhere('key', 'firewall');
        $this->assertSame('The firewall keeps its rules, and none of them lets RDP (port 3389) in.', $step['message'] ?? null);
    }

    public function test_app_ports_are_opened_while_the_firewall_is_on_and_closed_when_the_app_is_gone()
    {
        $this->syncRecipes();
        $this->offerApps();
        $server = $this->create();
        $this->turnOn($server);

        $installation = app(AppInstaller::class)->install($server, AppRecipe::query()->where('slug', 'wireguard')->firstOrFail(), ['port' => 51900], $this->admin, '127.0.0.1')->refresh();

        $this->assertSame(AppInstallationStatus::Installing, $installation->status);
        $this->assertContains('Proxmotion app: wireguard', $this->ruleComments($server));
        $this->assertSame([['app' => 'wireguard', 'protocol' => 'udp', 'port' => 51900]], app(ServerFirewalls::class)->describe($server)['app_rules']);

        $installation->forceFill(['status' => AppInstallationStatus::Removed])->save();
        app(ServerFirewalls::class)->refresh($server->refresh());

        $this->assertNotContains('Proxmotion app: wireguard', $this->ruleComments($server));
        $this->assertTrue($server->tasks()->where('type', TaskType::ApplyFirewall)->where('status', TaskStatus::Succeeded)->count() >= 2);
    }

    public function test_changes_made_in_proxmox_are_reported_and_restored()
    {
        $server = $this->create();
        $this->turnOn($server, [new FirewallRule('accept', 'tcp', '443')]);
        $vmid = $server->vmid;
        $this->proxmox->vms[$vmid]['firewall']['policy_in'] = 'ACCEPT';
        $this->proxmox->vms[$vmid]['rules'] = array_values(array_filter($this->proxmox->vms[$vmid]['rules'], fn (array $rule) => ! str_starts_with((string) $rule['comment'], 'Proxmotion rule')));

        $this->sync();

        $this->assertSame(['firewall_rules_differ', 'firewall_policy_open'], $this->firewallDrift()?->actual['problems']);

        $this->actingAs($this->admin)->post(route('admin.servers.enable-anti-spoofing', $server))->assertRedirect();

        $this->assertSame('DROP', $this->vm($server)['firewall']['policy_in']);
        $this->assertNull($this->firewallDrift());
    }

    public function test_admins_change_the_firewall_on_the_server_page()
    {
        $server = $this->create();

        $this->actingAs($this->admin)->get(route('admin.servers.show', $server))
            ->assertInertia(fn ($page) => $page->where('firewall.default_policy', 'accept')->where('firewall.rules', []));

        $this->actingAs($this->admin)->put(route('admin.servers.firewall', $server), [
            'default_policy' => 'drop',
            'rules' => [['action' => 'drop', 'protocol' => 'udp', 'port' => '53', 'source' => '2001:db8::/32', 'comment' => 'No DNS']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('drop', $server->refresh()->firewall_policy);
        $rule = collect($this->vm($server)['rules'])->firstWhere('comment', 'Proxmotion rule: No DNS');
        $this->assertSame(['DROP', 'udp', '53', '2001:db8::/32'], [$rule['action'], $rule['proto'], $rule['dport'], $rule['source']]);

        $this->actingAs($this->admin)->put(route('admin.servers.firewall', $server), [
            'default_policy' => 'drop',
            'rules' => [['action' => 'accept', 'protocol' => 'icmp', 'port' => '22', 'source' => null, 'comment' => null]],
        ])->assertSessionHasErrors('rules.0.port');
    }

    public function test_rule_sets_are_managed_and_kept_while_packages_use_them()
    {
        $rules = [['action' => 'accept', 'protocol' => 'tcp', 'port' => '80', 'source' => null, 'comment' => null]];

        $this->actingAs($this->admin)->post(route('admin.firewall-rulesets.store'), ['name' => 'Web', 'default_policy' => 'drop', 'is_active' => true, 'rules' => $rules])
            ->assertSessionHasNoErrors();
        $ruleset = FirewallRuleset::query()->sole();

        $this->actingAs($this->admin)->get(route('admin.firewall-rulesets.index'))
            ->assertInertia(fn ($page) => $page->where('rulesets.0.opens_remote_access', false));

        $this->actingAs($this->admin)->put(route('admin.packages.update', $this->package), [
            ...$this->package->only(['name', 'cpu_cores', 'memory_mib', 'ipv4_count', 'ipv6_count', 'snapshot_slots', 'backup_slots', 'sort_order']),
            'disk_gib' => 40,
            'network_models' => ['bridged'],
            'is_active' => true,
            'firewall_ruleset' => $ruleset->uuid,
        ])->assertSessionHasNoErrors();
        $this->assertSame($ruleset->id, $this->package->refresh()->firewall_ruleset_id);

        $this->actingAs($this->admin)->delete(route('admin.firewall-rulesets.destroy', $ruleset));
        $this->assertModelExists($ruleset);

        $this->actingAs($this->admin)->get(route('admin.servers.show', $this->create()))
            ->assertInertia(fn ($page) => $page->where('firewall.rulesets.0.value', $ruleset->uuid));
    }

    public function test_the_default_rule_follows_the_platform_among_other_rules_saved_on_the_server_page()
    {
        $server = $this->create();
        $this->turnOn($server);

        // The page sends the whole list back, the default rule included.
        $this->actingAs($this->admin)->put(route('admin.servers.firewall', $server), [
            'default_policy' => 'drop',
            'rules' => [
                ...app(ServerFirewalls::class)->describe($server)['rules'],
                ['action' => 'accept', 'protocol' => 'tcp', 'port' => '443', 'source' => null, 'comment' => 'HTTPS'],
            ],
        ])->assertSessionHasNoErrors();
        $this->assertSame([true, false], $server->firewallRules()->pluck('is_default')->all());

        $task = $this->reinstallOntoWindows($server);

        $this->assertSame(['3389', '443'], $server->firewallRules()->pluck('port')->all());
        $this->assertNull(collect($task->steps)->firstWhere('key', 'firewall')['message'] ?? null);
        $this->assertContains('Proxmotion rule: RDP', $this->ruleComments($server));
        $this->assertNotContains('Proxmotion rule: SSH', $this->ruleComments($server));

        $this->reinstallOnto($server, $this->osTemplate);

        $this->assertSame(['22', '443'], $server->firewallRules()->pluck('port')->all());
        $this->assertContains('Proxmotion rule: SSH', $this->ruleComments($server));
        $this->assertNotContains('Proxmotion rule: RDP', $this->ruleComments($server));
    }

    public function test_a_reinstall_drops_the_old_default_rule_when_another_rule_opens_the_new_port()
    {
        $server = $this->create();
        $this->turnOn($server, [
            ...FirewallRule::defaultsFor(null),
            new FirewallRule('accept', 'tcp', '3000-4000', comment: 'Range'),
        ]);

        $this->reinstallOntoWindows($server);

        $this->assertSame(['3389', '3000-4000'], $server->firewallRules()->pluck('port')->all());
        $this->assertSame([true, false], $server->firewallRules()->pluck('is_default')->all());
    }

    public function test_the_default_rule_follows_the_platform_while_the_firewall_accepts_by_default()
    {
        $server = $this->create();
        $this->turnOn($server);

        // Allowing by default again keeps the rules, the default one included.
        $this->actingAs($this->admin)->put(route('admin.servers.firewall', $server), [
            'default_policy' => 'accept',
            'rules' => app(ServerFirewalls::class)->describe($server)['rules'],
        ])->assertSessionHasNoErrors();

        $task = $this->reinstallOntoWindows($server);

        $this->assertSame('accept', $server->refresh()->firewall_policy);
        $this->assertSame(['3389'], $server->firewallRules()->pluck('port')->all());
        $this->assertNull(collect($task->steps)->firstWhere('key', 'firewall')['message'] ?? null);

        // Blocking again lets RDP in, not SSH.
        $this->actingAs($this->admin)->put(route('admin.servers.firewall', $server), [
            'default_policy' => 'drop',
            'rules' => app(ServerFirewalls::class)->describe($server)['rules'],
        ])->assertSessionHasNoErrors();

        $this->assertContains('Proxmotion rule: RDP', $this->ruleComments($server));
        $this->assertNotContains('Proxmotion rule: SSH', $this->ruleComments($server));
    }

    public function test_a_reinstall_leaves_no_note_while_the_firewall_accepts_by_default()
    {
        $server = $this->create();

        $task = $this->reinstallOntoWindows($server);

        $this->assertSame([], $server->firewallRules()->pluck('port')->all());
        $this->assertNull(collect($task->steps)->firstWhere('key', 'firewall')['message'] ?? null);
    }

    public function test_a_default_rule_someone_changed_stays()
    {
        $server = $this->create();
        $this->turnOn($server);

        $this->actingAs($this->admin)->put(route('admin.servers.firewall', $server), [
            'default_policy' => 'drop',
            'rules' => [['action' => 'accept', 'protocol' => 'tcp', 'port' => '22', 'source' => '198.51.100.0/24', 'comment' => 'SSH']],
        ])->assertSessionHasNoErrors();

        $task = $this->reinstallOntoWindows($server);

        $this->assertSame(['22'], $server->firewallRules()->pluck('port')->all());
        $step = collect($task->steps)->firstWhere('key', 'firewall');
        $this->assertSame('The firewall keeps its rules, and none of them lets RDP (port 3389) in.', $step['message'] ?? null);
    }

    private function reinstallOntoWindows(Server $server): Task
    {
        $windows = OsTemplate::factory()->windows()->create();
        $windows->proxmoxTemplates()->attach(ProxmoxTemplate::query()->forceCreate([
            'cluster_id' => $this->cluster->id,
            'node_id' => $this->node->id,
            'vmid' => 9001,
            'name' => 'tpl-windows',
        ]));

        return $this->reinstallOnto($server, $windows);
    }

    private function reinstallOnto(Server $server, OsTemplate $template): Task
    {
        $this->actingAs($this->admin)->post(route('admin.servers.reinstall', $server->refresh()), [
            'os_template' => $template->uuid,
            'ssh_keys' => '',
            'confirm_hostname' => $server->hostname,
        ])->assertSessionHasNoErrors();

        $task = $server->tasks()->where('type', TaskType::Reinstall)->latest('id')->firstOrFail();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);

        return $task;
    }
}
