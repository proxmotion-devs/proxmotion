<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\FirewallRuleset;
use App\Models\Server;
use App\Models\Task;

/**
 * GET/PUT /api/v1/servers/{id}/firewall, POST .../firewall/ruleset and
 * GET /api/v1/firewall-rulesets (docs/adr/0035).
 */
class FirewallApiTest extends ApiTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->provisionedServer();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/servers/{$this->server->uuid}/firewall{$suffix}";
    }

    /**
     * @return list<string>
     */
    private function proxmoxComments(): array
    {
        return array_map(fn (array $rule) => (string) ($rule['comment'] ?? ''), $this->proxmox->vms[$this->server->vmid]['rules']);
    }

    public function test_a_new_server_allows_everything()
    {
        $this->api('GET', $this->url())
            ->assertOk()
            ->assertExactJson(['data' => ['default_policy' => 'accept', 'rules' => [], 'app_rules' => []]]);

        $this->assertSame('ACCEPT', $this->proxmox->vms[$this->server->vmid]['firewall']['policy_in']);
    }

    public function test_rules_are_saved_and_written_to_the_vm_firewall()
    {
        $rules = [
            ['action' => 'accept', 'protocol' => 'tcp', 'port' => '22', 'source' => '198.51.100.0/24', 'comment' => 'Office'],
            ['action' => 'accept', 'protocol' => 'tcp', 'port' => '8000-8100', 'source' => null, 'comment' => null],
            ['action' => 'accept', 'protocol' => 'icmp', 'port' => null, 'source' => null, 'comment' => 'Ping'],
        ];

        $this->api('PUT', $this->url(), ['default_policy' => 'drop', 'rules' => $rules])
            ->assertAccepted()
            ->assertJsonPath('data.type', 'apply_firewall')
            ->assertJsonPath('data.status', 'succeeded');

        $this->api('GET', $this->url())
            ->assertJsonPath('data.default_policy', 'drop')
            ->assertJsonPath('data.rules', $rules);

        $vm = $this->proxmox->vms[$this->server->vmid];
        $this->assertSame('DROP', $vm['firewall']['policy_in']);
        $this->assertSame('ACCEPT', $vm['firewall']['policy_out']);
        $this->assertSame([
            'Proxmotion: no DHCPv6 server',
            'Proxmotion: no DHCP server',
            'Proxmotion rule: Office',
            'Proxmotion rule',
            'Proxmotion rule: Ping',
            'Proxmotion rule: Ping',
        ], $this->proxmoxComments());
        $this->assertSame(['in', 'ACCEPT', 'tcp', '22', '198.51.100.0/24'], [$vm['rules'][2]['type'], $vm['rules'][2]['action'], $vm['rules'][2]['proto'], $vm['rules'][2]['dport'], $vm['rules'][2]['source']]);
        $this->assertSame('8000:8100', $vm['rules'][3]['dport'], 'Proxmox writes ranges with a colon.');
        $this->assertSame(['icmp', 'ipv6-icmp'], [$vm['rules'][4]['proto'], $vm['rules'][5]['proto']]);

        $audit = AuditLog::query()->where('action', 'server.firewall_changed')->sole();
        $this->assertSame('accept', $audit->properties['before']['default_policy']);
        $this->assertCount(3, $audit->properties['after']['rules']);
    }

    public function test_blocking_by_default_without_an_allowing_rule_lets_ssh_in()
    {
        $this->api('PUT', $this->url(), ['default_policy' => 'drop', 'rules' => []])->assertAccepted();

        $this->api('GET', $this->url())->assertJsonPath('data.rules', [
            ['action' => 'accept', 'protocol' => 'tcp', 'port' => '22', 'source' => null, 'comment' => 'SSH'],
        ]);

        // Already blocking: an empty list blocks everything.
        $this->api('PUT', $this->url(), ['default_policy' => 'drop', 'rules' => []])->assertAccepted();
        $this->api('GET', $this->url())->assertJsonPath('data.rules', []);
    }

    public function test_rules_apply_while_everything_else_is_allowed()
    {
        // The rule an administrator wrote expecting it to work at once.
        $rule = ['action' => 'drop', 'protocol' => 'tcp', 'port' => '3389', 'source' => null, 'comment' => 'No RDP'];

        $this->api('PUT', $this->url(), ['default_policy' => 'accept', 'rules' => [$rule]])->assertAccepted();

        $vm = $this->proxmox->vms[$this->server->vmid];
        $this->assertSame('ACCEPT', $vm['firewall']['policy_in']);
        $written = collect($vm['rules'])->firstWhere('comment', 'Proxmotion rule: No RDP');
        $this->assertSame(['in', 'DROP', 'tcp', '3389'], [$written['type'], $written['action'], $written['proto'], $written['dport']]);
        $this->api('GET', $this->url())->assertJsonPath('data.default_policy', 'accept')->assertJsonPath('data.rules', [$rule]);
    }

    public function test_switching_back_to_allowing_keeps_the_rules()
    {
        $rule = ['action' => 'accept', 'protocol' => 'tcp', 'port' => '443', 'source' => null, 'comment' => null];
        $this->api('PUT', $this->url(), ['default_policy' => 'drop', 'rules' => [$rule]])->assertAccepted();

        $this->api('PUT', $this->url(), ['default_policy' => 'accept', 'rules' => [$rule]])->assertAccepted();

        $this->assertSame('ACCEPT', $this->proxmox->vms[$this->server->vmid]['firewall']['policy_in']);
        $this->assertContains('Proxmotion rule', $this->proxmoxComments());
    }

    public function test_invalid_rules_are_refused()
    {
        $put = fn (array $rule) => $this->api('PUT', $this->url(), ['default_policy' => 'drop', 'rules' => [[
            'action' => 'accept', 'protocol' => 'tcp', 'port' => null, 'source' => null, 'comment' => null, ...$rule,
        ]]]);

        $put(['port' => '70000'])->assertUnprocessable()->assertJsonValidationErrors('rules.0.port');
        $put(['port' => '2000-1000'])->assertUnprocessable()->assertJsonValidationErrors('rules.0.port');
        $put(['protocol' => 'icmp', 'port' => '22'])->assertUnprocessable()->assertJsonValidationErrors(['rules.0.port' => 'Only TCP and UDP rules can have a port.']);
        $put(['source' => 'office.example.com'])->assertUnprocessable()->assertJsonValidationErrors('rules.0.source');
        $put(['action' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('rules.0.action');
        $put(['comment' => "two\nlines"])->assertUnprocessable()->assertJsonValidationErrors('rules.0.comment');

        $tooMany = array_fill(0, 51, ['action' => 'accept', 'protocol' => 'any', 'port' => null, 'source' => null, 'comment' => null]);
        $this->api('PUT', $this->url(), ['default_policy' => 'drop', 'rules' => $tooMany])->assertUnprocessable()->assertJsonValidationErrors('rules');

        $this->assertFalse(Task::query()->where('type', TaskType::ApplyFirewall)->exists());
    }

    public function test_a_rule_set_replaces_or_follows_the_rules()
    {
        $web = FirewallRuleset::create(['name' => 'Web', 'rules' => [
            ['action' => 'accept', 'protocol' => 'tcp', 'port' => '80', 'source' => null, 'comment' => 'HTTP'],
            ['action' => 'accept', 'protocol' => 'tcp', 'port' => '443', 'source' => null, 'comment' => 'HTTPS'],
        ], 'is_active' => true]);
        FirewallRuleset::create(['name' => 'Hidden', 'rules' => [], 'is_active' => false]);

        $this->api('GET', '/api/v1/firewall-rulesets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $web->uuid)
            ->assertJsonPath('data.0.default_policy', 'drop')
            ->assertJsonPath('data.0.rules.1.port', '443');

        $this->api('PUT', $this->url(), ['default_policy' => 'accept', 'rules' => [['action' => 'accept', 'protocol' => 'tcp', 'port' => '22', 'source' => null, 'comment' => null]]])->assertAccepted();

        $this->api('POST', $this->url('/ruleset'), ['ruleset' => $web->uuid, 'mode' => 'append'])->assertAccepted();
        $this->api('GET', $this->url())->assertJsonPath('data.default_policy', 'accept', 'Appending keeps the policy.')->assertJsonCount(3, 'data.rules');

        $this->api('POST', $this->url('/ruleset'), ['ruleset' => $web->uuid, 'mode' => 'replace'])->assertAccepted();
        $this->api('GET', $this->url())->assertJsonPath('data.default_policy', 'drop', 'The rule set\'s.')->assertJsonCount(2, 'data.rules')->assertJsonPath('data.rules.0.comment', 'HTTP');

        $hidden = FirewallRuleset::query()->where('name', 'Hidden')->sole();
        $this->api('POST', $this->url('/ruleset'), ['ruleset' => $hidden->uuid, 'mode' => 'replace'])->assertUnprocessable();
    }

    public function test_only_active_and_suspended_servers_change()
    {
        $this->server->forceFill(['status' => ServerStatus::Reinstalling])->save();

        $this->api('PUT', $this->url(), ['default_policy' => 'drop', 'rules' => []])->assertConflict();
    }

    public function test_a_running_task_is_a_conflict()
    {
        (new Task)->forceFill(['server_id' => $this->server->id, 'type' => TaskType::Reboot, 'status' => TaskStatus::Running, 'steps' => []])->save();

        $this->api('PUT', $this->url(), ['default_policy' => 'drop', 'rules' => []])->assertConflict();

        $this->assertSame('accept', $this->server->refresh()->firewall_policy, 'Rolled back.');
    }
}
