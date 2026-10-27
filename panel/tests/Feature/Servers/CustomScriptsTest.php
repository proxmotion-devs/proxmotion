<?php

namespace Tests\Feature\Servers;

use App\Domain\Servers\ServerReinstaller;
use App\Enums\GuestScriptEvent;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\CustomScript;
use App\Models\GuestScript;
use App\Models\GuestScriptLog;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use App\Provisioning\TaskPlan;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Administrators' own scripts per OS template (docs/adr/0043): run after the
 * fixed script of their events, by hand from the server page, and in trials.
 */
class CustomScriptsTest extends TestCase
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
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    /**
     * @param  list<GuestScriptEvent>  $events
     */
    private function custom(string $name, string $content = "echo hi\n", array $events = [], bool $manual = false, ?OsTemplate $template = null): CustomScript
    {
        $script = new CustomScript;
        $script->forceFill([
            'os_template_id' => ($template ?? $this->osTemplate)->id,
            'name' => $name,
            'manual' => $manual,
            'events' => array_map(fn (GuestScriptEvent $event) => $event->value, $events),
            'position' => CustomScript::query()->count() + 1,
            'content' => $content,
        ])->save();

        return $script;
    }

    private function step(Task $task, string $key): ?array
    {
        return collect($task->steps)->firstWhere('key', $key);
    }

    /**
     * @return list<list<string>>
     */
    private function commands(): array
    {
        return array_column($this->proxmox->guestCommands, 'command');
    }

    public function test_scripts_bound_to_the_post_install_event_run_after_creation_even_without_the_templates_own_script()
    {
        $script = $this->custom('Monitoring agent', "#!/bin/sh\necho monitor\n", [GuestScriptEvent::Provisioned]);
        $this->custom('Not bound', "echo no\n", [GuestScriptEvent::DiskResized], manual: true);

        $server = app(ServerCreator::class)->create($this->order(['hostname' => 'web2.example.com']))->refresh();
        $files = $this->proxmox->guestFiles[$server->vmid];
        $task = $server->tasks()->sole();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame("#!/bin/sh\necho monitor\n", $files["/usr/local/sbin/proxmotion-script-{$script->id}"]);
        $inputs = json_decode($files["/etc/proxmotion/script-{$script->id}.json"], true);
        $this->assertSame('provisioned', $inputs['trigger']);
        $this->assertSame('create', $inputs['reason']);
        $this->assertSame('web2.example.com', $inputs['hostname']);
        $this->assertCount(1, $inputs['addresses']);
        $this->assertStringContainsString("trigger=provisioned\n", $files["/etc/proxmotion/script-{$script->id}.vars"]);
        $this->assertNotNull($step = $this->step($task, 'script:provisioned:Monitoring agent'), 'The script has its own step.');
        $this->assertNull($step['message']);
        $this->assertNull($this->step($task, 'guest_provisioned'), 'The template has no script of its own.');
        $this->assertCount(1, array_filter($this->commands(), fn (array $command) => str_starts_with($command[1] ?? '', '/usr/local/sbin/proxmotion-script-')), 'Only the bound script ran.');

        $log = GuestScriptLog::query()->where('custom_script_id', $script->id)->sole();
        $this->assertSame('Monitoring agent', $log->script_name);
        $this->assertSame(GuestScriptEvent::Provisioned, $log->event);
        $this->assertSame('published', $log->source);
    }

    public function test_they_follow_the_templates_own_script_in_their_order_and_a_failing_one_only_leaves_a_note()
    {
        $own = new GuestScript;
        $own->forceFill(['os_template_id' => $this->osTemplate->id, 'event' => GuestScriptEvent::Provisioned, 'content' => "echo own\n"])->save();
        $second = $this->custom('Second', "echo second\n", [GuestScriptEvent::Provisioned]);
        $first = $this->custom('First', "exit 3\n", [GuestScriptEvent::Provisioned]);
        $first->forceFill(['position' => 0])->save();
        $this->proxmox->execHandler = fn (array $command) => $command === ['sh', "/usr/local/sbin/proxmotion-script-{$first->id}"]
            ? [3, '', "agent missing\n"]
            : [0, '', ''];

        $server = app(ServerCreator::class)->create($this->order(['hostname' => 'web2.example.com']))->refresh();
        $task = $server->tasks()->sole();
        $ran = array_values(array_filter($this->commands(), fn (array $command) => str_starts_with($command[1] ?? '', '/usr/local/sbin/proxmotion-')));

        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame([
            ['sh', '/usr/local/sbin/proxmotion-provisioned'],
            ['sh', "/usr/local/sbin/proxmotion-script-{$first->id}"],
            ['sh', "/usr/local/sbin/proxmotion-script-{$second->id}"],
        ], $ran);
        $this->assertSame('The script First failed: agent missing', $this->step($task, 'script:provisioned:First')['message']);
        $this->assertNull($this->step($task, 'script:provisioned:Second')['message']);
    }

    public function test_a_script_bound_to_two_events_of_one_task_records_a_step_for_each()
    {
        $this->package->update(['ipv4_count' => 2]);
        $script = $this->custom('Monitoring agent', "echo x\n", [GuestScriptEvent::AddressesChanged, GuestScriptEvent::Provisioned]);
        $runs = 0;
        // The first run (after the additional addresses) fails, the second succeeds.
        $this->proxmox->execHandler = function (array $command) use ($script, &$runs) {
            return $command === ['sh', "/usr/local/sbin/proxmotion-script-{$script->id}"] && ++$runs === 1 ? [1, '', "no network yet\n"] : [0, '', ''];
        };

        $server = app(ServerCreator::class)->create($this->order(['hostname' => 'web2.example.com', 'spec' => ServerSpec::fromPackage($this->package)]))->refresh();
        $task = $server->tasks()->sole();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(2, $runs);
        $this->assertSame('The script Monitoring agent failed: no network yet', $this->step($task, 'script:addresses_changed:Monitoring agent')['message']);
        $this->assertSame('done', $this->step($task, 'script:provisioned:Monitoring agent')['status']);
        $this->assertNull($this->step($task, 'script:provisioned:Monitoring agent')['message']);

        $planned = app(TaskPlan::class)->steps($task);
        $this->assertSame(1, count(array_keys($planned, 'script:addresses_changed:Monitoring agent', true)));
        $this->assertSame(1, count(array_keys($planned, 'script:provisioned:Monitoring agent', true)));
        $this->assertSame([GuestScriptEvent::AddressesChanged, GuestScriptEvent::Provisioned], GuestScriptLog::query()->where('custom_script_id', $script->id)->orderBy('id')->pluck('event')->all());
    }

    public function test_unpublished_scripts_never_run_at_events()
    {
        $script = $this->custom('Draft only', "echo x\n", [GuestScriptEvent::Provisioned]);
        $script->forceFill(['content' => null, 'draft_content' => "echo draft\n"])->save();

        $server = app(ServerCreator::class)->create($this->order(['hostname' => 'web2.example.com']))->refresh();

        $this->assertNull($this->step($server->tasks()->sole(), 'script:provisioned:Draft only'));
        $this->assertSame(0, GuestScriptLog::query()->count());
    }

    public function test_the_task_plan_lists_them_before_they_run()
    {
        $this->custom('Monitoring agent', "echo x\n", [GuestScriptEvent::Provisioned]);

        $task = app(ServerReinstaller::class)->request($this->server, $this->osTemplate, [], $this->admin, null)->refresh();

        $this->assertContains('script:provisioned:Monitoring agent', app(TaskPlan::class)->steps($task));
        $this->assertSame('reinstall', json_decode($this->proxmox->guestFiles[$this->server->vmid]["/etc/proxmotion/script-{$this->osTemplate->customScripts()->sole()->id}.json"], true)['reason']);
    }

    public function test_the_rename_of_a_vm_named_after_its_address_runs_no_hostname_scripts()
    {
        // Named from its location's template before 2026-10-20 (docs/adr/0031 §2a): the pipeline sets the real hostname inside.
        $this->server->forceFill(['vm_name' => '203.0.113.9'])->save();
        $script = $this->custom('Rename hook', "echo renamed\n", [GuestScriptEvent::HostnameChanged]);

        $task = app(TaskRunner::class)->start($this->server, TaskType::Reinstall)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertNotNull($this->step($task, GuestScriptEvent::HostnameChanged->step()), 'The template\'s hostname script ran.');
        $this->assertNull($this->step($task, 'script:hostname_changed:Rename hook'));
        $this->assertFalse(GuestScriptLog::query()->where('custom_script_id', $script->id)->exists());
    }

    public function test_after_a_password_reset_they_run_once_the_password_is_stored_and_never_get_it()
    {
        $script = $this->custom('Notify', "echo done\n", [GuestScriptEvent::PasswordReset]);

        $task = app(TaskRunner::class)->start($this->server, TaskType::ResetPassword, $this->admin, secret: 'chosen-password-123')->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame('chosen-password-123', $this->server->refresh()->root_password);
        $run = collect($this->proxmox->guestCommands)->firstWhere('command', ['sh', "/usr/local/sbin/proxmotion-script-{$script->id}"]);
        $this->assertNotNull($run, 'The script ran after the agent set the password.');
        $this->assertNull($run['input'], 'Nothing on standard input.');
        $inputs = json_decode($this->proxmox->guestFiles[$this->server->vmid]["/etc/proxmotion/script-{$script->id}.json"], true);
        $this->assertSame('root', $inputs['username']);
        $this->assertStringNotContainsString('chosen-password-123', json_encode($inputs));
    }

    public function test_a_failing_script_after_a_password_reset_does_not_fail_it()
    {
        $script = $this->custom('Notify', "exit 1\n", [GuestScriptEvent::PasswordReset]);
        $this->proxmox->execHandler = fn (array $command) => $command === ['sh', "/usr/local/sbin/proxmotion-script-{$script->id}"] ? [1, '', "nope\n"] : [0, '', ''];

        $task = app(TaskRunner::class)->start($this->server, TaskType::ResetPassword, $this->admin, secret: 'chosen-password-123')->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame('The script Notify failed: nope', $this->step($task, 'script:password_reset:Notify')['message']);
    }

    public function test_administrators_run_a_manual_script_from_the_server_page()
    {
        $script = $this->custom('Clear cache', "#!/bin/sh\necho cleared\n", manual: true);
        $this->proxmox->execHandler = fn (array $command) => $command === ['sh', "/usr/local/sbin/proxmotion-script-{$script->id}"] ? [0, "cleared\n", ''] : [0, '', ''];

        $this->actingAs($this->admin)
            ->post(route('admin.servers.scripts.run', [$this->server, $script]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $task = $this->server->tasks()->where('type', TaskType::GuestScript)->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $log = GuestScriptLog::query()->sole();
        $this->assertNull($log->event);
        $this->assertSame('manual', $log->inputs['trigger']);
        $this->assertSame("cleared\n", $log->stdout);
        $audit = AuditLog::query()->where('action', 'custom_script.run')->sole();
        $this->assertSame('Clear cache', $audit->properties['script']);
        $this->assertTrue($audit->properties['manual']);

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('scripts', [['uuid' => $script->uuid, 'name' => 'Clear cache']])
                ->has('scriptRuns', 1)
                ->where('scriptRuns.0.kind', 'manual')
                ->where('scriptRuns.0.script_name', 'Clear cache'));
    }

    public function test_a_failing_manual_script_fails_its_task()
    {
        $script = $this->custom('Clear cache', "exit 2\n", manual: true);
        $this->proxmox->execHandler = fn (array $command) => $command === ['sh', "/usr/local/sbin/proxmotion-script-{$script->id}"] ? [2, '', "no cache\n"] : [0, '', ''];

        $this->actingAs($this->admin)->post(route('admin.servers.scripts.run', [$this->server, $script]));

        $task = $this->server->tasks()->where('type', TaskType::GuestScript)->sole();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('The script Clear cache failed: no cache', $task->error);
    }

    public function test_only_published_manual_scripts_of_the_servers_template_can_be_run_from_the_server_page()
    {
        $eventOnly = $this->custom('At events', "echo\n", [GuestScriptEvent::Provisioned]);
        $draft = $this->custom('Draft', "echo\n", manual: true);
        $draft->forceFill(['content' => null, 'draft_content' => "echo\n"])->save();
        $elsewhere = $this->custom('Elsewhere', "echo\n", manual: true, template: OsTemplate::factory()->create());

        foreach ([$eventOnly, $draft, $elsewhere] as $script) {
            $this->actingAs($this->admin)->post(route('admin.servers.scripts.run', [$this->server, $script]))->assertNotFound();
        }

        $this->assertSame(0, $this->server->tasks()->where('type', TaskType::GuestScript)->count());
        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page->where('scripts', []));
    }

    public function test_a_draft_is_tried_on_a_server_of_the_template()
    {
        $script = $this->custom('Agent', "echo live\n");
        $script->forceFill(['draft_content' => "echo draft\n"])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.os-templates.custom-scripts.run', [$this->osTemplate, $script]), ['server' => $this->server->uuid, 'draft' => true])
            ->assertSessionHasNoErrors();

        $files = $this->proxmox->guestFiles[$this->server->vmid];
        $this->assertSame("echo draft\n", $files["/usr/local/sbin/proxmotion-script-{$script->id}-draft"]);
        $this->assertArrayNotHasKey("/usr/local/sbin/proxmotion-script-{$script->id}", $files);
        $log = GuestScriptLog::query()->sole();
        $this->assertSame('draft', $log->source);
        $this->assertSame('trial', $log->inputs['trigger']);

        $this->actingAs($this->admin)
            ->get(route('admin.os-templates.scripts', $this->osTemplate))
            ->assertInertia(fn (Assert $page) => $page
                ->has('customScripts', 1)
                ->where('customScripts.0.draft', "echo draft\n")
                ->where("customLogs.{$script->uuid}.0.kind", 'trial')
                ->where('logs.provisioned', []));
    }

    public function test_the_scripts_page_lists_the_latest_runs_of_every_script_in_a_fixed_number_of_queries()
    {
        $scripts = [$this->custom('First'), $this->custom('Second'), $this->custom('Third')];

        foreach ($scripts as $script) {
            foreach (range(1, 10) as $run) {
                $log = new GuestScriptLog;
                $log->forceFill([
                    'server_id' => $this->server->id,
                    'os_template_id' => $this->osTemplate->id,
                    'custom_script_id' => $script->id,
                    'script_name' => $script->name,
                    'script' => "echo hi\n",
                    'inputs' => [],
                    'source' => GuestScriptLog::SOURCE_PUBLISHED,
                    'trace' => false,
                    'status' => 'succeeded',
                    'exit_code' => $run,
                ])->save();
            }
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->actingAs($this->admin)
            ->get(route('admin.os-templates.scripts', $this->osTemplate))
            ->assertInertia(function (Assert $page) use ($scripts) {
                foreach ($scripts as $script) {
                    // At most 8 per script, the newest first.
                    $page->has("customLogs.{$script->uuid}", 8)->where("customLogs.{$script->uuid}.0.exit_code", 10);
                }
            });
        $withThree = $queries;

        $this->custom('Fourth');
        $queries = 0;
        $this->actingAs($this->admin)->get(route('admin.os-templates.scripts', $this->osTemplate))->assertOk();

        $this->assertSame($withThree, $queries, 'One more script asks no more queries.');
    }

    public function test_scripts_are_added_as_drafts_and_their_triggers_are_validated()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.os-templates.custom-scripts.store', $this->osTemplate), [
                'name' => 'Monitoring agent',
                'manual' => true,
                'events' => ['disk_resized', 'provisioned'],
                'script' => "#!/bin/sh\r\necho hi\r\n",
            ])
            ->assertSessionHasNoErrors();

        $script = CustomScript::query()->sole();
        $this->assertNull($script->content);
        $this->assertSame("#!/bin/sh\necho hi\n", $script->draft_content);
        $this->assertSame(['provisioned', 'disk_resized'], $script->events, 'In the order of the events.');
        $this->assertTrue(AuditLog::query()->where('action', 'custom_script.created')->exists());

        $this->actingAs($this->admin)
            ->post(route('admin.os-templates.custom-scripts.store', $this->osTemplate), ['name' => 'Monitoring agent', 'manual' => false, 'events' => ['reboot'], 'script' => 'x'])
            ->assertSessionHasErrors(['name', 'events.0']);
        $this->actingAs($this->admin)
            ->post(route('admin.os-templates.custom-scripts.store', $this->osTemplate), ['name' => 'rm -rf /; echo', 'manual' => false, 'events' => [], 'script' => 'x'])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->admin)
            ->patch(route('admin.os-templates.custom-scripts.update', [$this->osTemplate, $script]), ['name' => 'Monitoring', 'manual' => false, 'events' => ['hostname_changed']])
            ->assertSessionHasNoErrors();
        $script->refresh();
        $this->assertSame('Monitoring', $script->name);
        $this->assertFalse($script->manual);
        $this->assertSame(['hostname_changed'], $script->events);
        $this->assertSame(['name' => 'Monitoring agent', 'manual' => true, 'events' => ['provisioned', 'disk_resized']], AuditLog::query()->where('action', 'custom_script.updated')->sole()->properties['before']);

        $this->actingAs($this->admin)->post(route('admin.os-templates.custom-scripts.publish', [$this->osTemplate, $script]));
        $this->assertSame("#!/bin/sh\necho hi\n", $script->refresh()->content);
        $this->assertNull($script->draft_content);
    }

    public function test_going_back_drops_the_runs_without_an_event_and_makes_the_event_required_again()
    {
        $script = $this->custom('Clear cache', "echo\n", [GuestScriptEvent::Provisioned], manual: true);
        app(ServerCreator::class)->create($this->order(['hostname' => 'web2.example.com']));
        $this->actingAs($this->admin)->post(route('admin.servers.scripts.run', [$this->server, $script]));
        $this->assertSame(1, GuestScriptLog::query()->whereNull('event')->count(), 'The manual run has no event.');

        $migration = require database_path('migrations/2026_12_03_000000_create_custom_scripts_table.php');
        $migration->down();

        $this->assertFalse(Schema::hasTable('custom_scripts'));
        $this->assertFalse(Schema::hasColumn('guest_script_logs', 'custom_script_id'));
        $this->assertFalse(Schema::hasColumn('guest_script_logs', 'script_name'));
        $this->assertSame(['provisioned'], DB::table('guest_script_logs')->pluck('event')->all());
        $this->assertFalse(collect(Schema::getColumns('guest_script_logs'))->firstWhere('name', 'event')['nullable']);

        $migration->up();
        $this->assertTrue(Schema::hasTable('custom_scripts'));
    }

    public function test_scripts_of_another_template_are_not_found_under_this_one()
    {
        $elsewhere = $this->custom('Elsewhere', "echo\n", template: OsTemplate::factory()->create());

        $this->actingAs($this->admin)
            ->post(route('admin.os-templates.custom-scripts.publish', [$this->osTemplate, $elsewhere]))
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->delete(route('admin.os-templates.custom-scripts.destroy', [$this->osTemplate, $elsewhere]))
            ->assertNotFound();

        $this->assertTrue($elsewhere->exists());
    }

    public function test_scripts_are_reordered_and_deleted_runs_keep_their_name()
    {
        $first = $this->custom('First', "echo\n", manual: true);
        $second = $this->custom('Second', "echo\n");

        $this->actingAs($this->admin)
            ->post(route('admin.os-templates.custom-scripts.move', [$this->osTemplate, $second]), ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(['Second', 'First'], $this->osTemplate->customScripts()->pluck('name')->all());

        $this->actingAs($this->admin)->post(route('admin.servers.scripts.run', [$this->server, $first]));
        $this->actingAs($this->admin)
            ->delete(route('admin.os-templates.custom-scripts.destroy', [$this->osTemplate, $first]))
            ->assertRedirect();

        $this->assertFalse(CustomScript::query()->whereKey($first->id)->exists());
        $log = GuestScriptLog::query()->sole();
        $this->assertNull($log->custom_script_id);
        $this->assertSame('First', $log->script_name);
        $this->assertSame('First', AuditLog::query()->where('action', 'custom_script.deleted')->sole()->properties['script']);
    }
}
