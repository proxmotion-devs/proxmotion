<?php

namespace Tests\Feature\Servers;

use App\Enums\GuestScriptEvent;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\GuestScript;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
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
 * Guest scripts per OS template (docs/adr/0010): defaults, overrides,
 * Windows, the critical password script and the admin scripts page.
 */
class GuestScriptsTest extends TestCase
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

    private function override(GuestScriptEvent $event, string $content, ?OsTemplate $template = null): void
    {
        $script = new GuestScript;
        $script->forceFill(['os_template_id' => ($template ?? $this->osTemplate)->id, 'event' => $event, 'content' => $content])->save();
    }

    private function grow(int $diskMib = 61440, int $ipv4 = 1): Task
    {
        $spec = new ServerSpec(cpuCores: 2, memoryMib: 4096, diskMib: $diskMib, ipv4Count: $ipv4, ipv6Count: 0, portSpeedMbps: 1000);

        return app(ResourceChanger::class)->request($this->server, new ResourceChange($spec, $this->package), $this->admin, null)->refresh();
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

    public function test_a_grown_disk_is_grown_inside_the_guest_with_the_default_script()
    {
        $task = $this->grow();
        $vmid = $this->server->vmid;

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(file_get_contents(resource_path('guest/linux/disk.sh')), $this->proxmox->guestFiles[$vmid]['/usr/local/sbin/proxmotion-disk']);
        $this->assertSame(61440, json_decode($this->proxmox->guestFiles[$vmid]['/etc/proxmotion/disk.json'], true)['disk_mib']);
        $this->assertStringContainsString("disk_mib=61440\n", $this->proxmox->guestFiles[$vmid]['/etc/proxmotion/disk.vars']);
        $this->assertContains(['sh', '/usr/local/sbin/proxmotion-disk'], $this->commands());
        $this->assertNotNull($step = $this->step($task, 'guest_disk'), 'The disk step did not run.');
        $this->assertNull($step['message']);
    }

    public function test_the_disk_script_only_runs_when_the_disk_grew()
    {
        $task = $this->grow(diskMib: 40960, ipv4: 2);

        $this->assertNull($this->step($task, 'guest_disk'));
        $this->assertNotContains(['sh', '/usr/local/sbin/proxmotion-disk'], $this->commands());
    }

    public function test_without_an_agent_the_disk_step_says_what_to_do()
    {
        $this->proxmox->agentDown[$this->server->vmid] = true;

        $task = $this->grow();

        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertStringStartsWith('The guest agent did not answer: grow the file system', $this->step($task, 'guest_disk')['message']);
    }

    public function test_a_failing_disk_script_is_noted()
    {
        $this->proxmox->execHandler = fn (array $command) => $command === ['sh', '/usr/local/sbin/proxmotion-disk']
            ? [4, '', "Unsupported root file system zfs: grow it by hand.\n"]
            : [0, '', ''];

        $task = $this->grow();

        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame('The file system could not be grown inside the server: Unsupported root file system zfs: grow it by hand.', $this->step($task, 'guest_disk')['message']);
    }

    public function test_the_templates_own_script_replaces_the_default()
    {
        $this->override(GuestScriptEvent::AddressesChanged, "#!/bin/sh\necho custom\n");

        $this->grow(diskMib: 40960, ipv4: 2);

        $this->assertSame("#!/bin/sh\necho custom\n", $this->proxmox->guestFiles[$this->server->vmid]['/usr/local/sbin/proxmotion-addresses']);
    }

    public function test_windows_templates_run_powershell_with_json_inputs()
    {
        $this->osTemplate->forceFill(['platform' => 'windows'])->save();

        $this->grow(ipv4: 2);
        $files = $this->proxmox->guestFiles[$this->server->vmid];

        $this->assertSame(file_get_contents(resource_path('guest/windows/disk.ps1')), $files['C:\\ProgramData\\Proxmotion\\disk.ps1']);
        $this->assertSame(file_get_contents(resource_path('guest/windows/addresses.ps1')), $files['C:\\ProgramData\\Proxmotion\\addresses.ps1']);
        $this->assertCount(1, json_decode($files['C:\\ProgramData\\Proxmotion\\addresses.json'], true)['addresses']);
        $this->assertArrayNotHasKey('/etc/proxmotion/addresses.vars', $files);
        $this->assertContains(
            ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', 'C:\\ProgramData\\Proxmotion\\disk.ps1'],
            $this->commands(),
        );
    }

    public function test_the_post_install_script_runs_after_creation_only_when_the_template_has_one()
    {
        $this->assertSame([], $this->proxmox->guestCommands, 'Nothing to run for the server of setUp.');

        $this->override(GuestScriptEvent::Provisioned, "#!/bin/sh\napt-get install -y htop\n");
        $server = app(ServerCreator::class)->create($this->order(['hostname' => 'web2.example.com']))->refresh();
        $files = $this->proxmox->guestFiles[$server->vmid];

        $this->assertSame("#!/bin/sh\napt-get install -y htop\n", $files['/usr/local/sbin/proxmotion-provisioned']);
        $inputs = json_decode($files['/etc/proxmotion/provisioned.json'], true);
        $this->assertSame('web2.example.com', $inputs['hostname']);
        $this->assertCount(1, $inputs['addresses'], 'All addresses, the primary one included.');
        $this->assertNotNull($step = $this->step($server->tasks()->sole(), 'guest_provisioned'), 'The script step did not run.');
        $this->assertNull($step['message']);
    }

    public function test_a_password_script_gets_the_password_on_standard_input()
    {
        $this->override(GuestScriptEvent::PasswordReset, "#!/bin/sh\nread -r password\necho \"root:\$password\" | chpasswd\n");

        $task = app(TaskRunner::class)->start($this->server, TaskType::ResetPassword, $this->admin, secret: 'chosen-password-123')->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $run = collect($this->proxmox->guestCommands)->firstWhere('command', ['sh', '/usr/local/sbin/proxmotion-password']);
        $this->assertSame("chosen-password-123\n", $run['input']);
        $this->assertSame([], $this->proxmox->guestPasswords, 'The agent did not set it itself.');
        $this->assertSame('root', json_decode($this->proxmox->guestFiles[$this->server->vmid]['/etc/proxmotion/password.json'], true)['username']);

        foreach ($this->proxmox->guestFiles[$this->server->vmid] as $content) {
            $this->assertStringNotContainsString('chosen-password-123', $content, 'The password is never written to a file.');
        }

        $this->assertSame('chosen-password-123', $this->server->refresh()->root_password);
    }

    public function test_a_failing_password_script_fails_the_reset()
    {
        $this->override(GuestScriptEvent::PasswordReset, "#!/bin/sh\nexit 1\n");
        $before = $this->server->root_password;
        $this->proxmox->execHandler = fn (array $command) => $command === ['sh', '/usr/local/sbin/proxmotion-password']
            ? [1, '', "chpasswd: user not found\n"]
            : [0, '', ''];

        $task = app(TaskRunner::class)->start($this->server, TaskType::ResetPassword, $this->admin)->refresh();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('The password reset script failed: chpasswd: user not found', $task->error);
        $this->assertSame($before, $this->server->refresh()->root_password, 'No new password is stored or shown.');
    }

    public function test_windows_passwords_are_set_for_the_administrator()
    {
        $this->osTemplate->forceFill(['platform' => 'windows'])->save();

        app(TaskRunner::class)->start($this->server, TaskType::ResetPassword, $this->admin, secret: 'chosen-password-123');

        $this->assertSame(['Administrator' => 'chosen-password-123'], $this->proxmox->guestPasswords[$this->server->vmid]);
    }

    public function test_the_scripts_page_shows_defaults_and_overrides()
    {
        $this->override(GuestScriptEvent::Provisioned, 'echo hi');

        $this->actingAs($this->admin)
            ->get(route('admin.os-templates.scripts', $this->osTemplate))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/os-templates/Scripts')
                ->where('template.platform', 'linux')
                ->has('scripts', 4)
                ->where('scripts.0.event', 'provisioned')
                ->where('scripts.0.content', 'echo hi')
                ->where('scripts.0.default', null)
                ->where('scripts.1.default', file_get_contents(resource_path('guest/linux/addresses.sh')))
                ->where('scripts.2.inputs', ['server', 'hostname', 'mac', 'disk_mib'])
                ->where('scripts.3.can_be_tried', false)
                ->where('scripts.3.critical', true)
                ->where('servers', [['value' => $this->server->uuid, 'label' => $this->server->hostname]]));
    }

    public function test_admins_save_and_reset_scripts_and_both_are_audited()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.os-templates.scripts.update', [$this->osTemplate, 'disk_resized']), ['script' => "#!/bin/sh\r\necho one\r\n"])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->put(route('admin.os-templates.scripts.update', [$this->osTemplate, 'disk_resized']), ['script' => "#!/bin/sh\necho two\n"]);

        $this->assertSame("#!/bin/sh\necho two\n", GuestScript::query()->sole()->content);
        $updates = AuditLog::query()->where('action', 'guest_script.updated')->orderBy('id')->get();
        $this->assertSame([null, "#!/bin/sh\necho one\n"], $updates->pluck('properties.before')->all(), 'Line endings are normalised.');
        $this->assertSame("#!/bin/sh\necho two\n", $updates[1]->properties['after']);

        $this->actingAs($this->admin)->delete(route('admin.os-templates.scripts.destroy', [$this->osTemplate, 'disk_resized']));

        $this->assertSame(0, GuestScript::query()->count());
        $this->assertSame("#!/bin/sh\necho two\n", AuditLog::query()->where('action', 'guest_script.reset')->sole()->properties['before']);
    }

    public function test_scripts_are_validated()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.os-templates.scripts.update', [$this->osTemplate, 'disk_resized']), ['script' => str_repeat('x', 46081)])
            ->assertSessionHasErrors('script');
        $this->actingAs($this->admin)
            ->put(route('admin.os-templates.scripts.update', [$this->osTemplate, 'disk_resized']), ['script' => ''])
            ->assertSessionHasErrors('script');
        $this->actingAs($this->admin)
            ->put(route('admin.os-templates.scripts.update', [$this->osTemplate, 'unknown']), ['script' => 'x'])
            ->assertNotFound();
    }

    public function test_admins_try_a_script_on_a_server_of_the_template()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.os-templates.scripts.run', [$this->osTemplate, 'disk_resized']), ['server' => $this->server->uuid])
            ->assertSessionHasNoErrors();

        $task = $this->server->tasks()->where('type', TaskType::GuestScript)->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertContains(['sh', '/usr/local/sbin/proxmotion-disk'], $this->commands());
        $this->assertSame('disk_resized', AuditLog::query()->where('action', 'guest_script.run')->sole()->properties['event']);
    }

    public function test_a_failing_trial_fails_its_task()
    {
        $this->proxmox->execHandler = fn (array $command) => [3, '', "growpart is not installed.\n"];

        $this->actingAs($this->admin)
            ->post(route('admin.os-templates.scripts.run', [$this->osTemplate, 'disk_resized']), ['server' => $this->server->uuid]);

        $task = $this->server->tasks()->where('type', TaskType::GuestScript)->sole();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('The file system could not be grown inside the server: growpart is not installed.', $task->error);
    }

    public function test_trials_are_limited_to_servers_of_the_template_and_never_the_password_script()
    {
        $other = OsTemplate::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.os-templates.scripts.run', [$other, 'disk_resized']), ['server' => $this->server->uuid])
            ->assertSessionHasErrors('server');
        $this->actingAs($this->admin)
            ->post(route('admin.os-templates.scripts.run', [$this->osTemplate, 'password_reset']), ['server' => $this->server->uuid])
            ->assertNotFound();

        $this->assertSame(0, $this->server->tasks()->where('type', TaskType::GuestScript)->count());
    }
}
