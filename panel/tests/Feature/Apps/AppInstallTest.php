<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\AppEligibility;
use App\Domain\Apps\AppInstallConflict;
use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppNotEligible;
use App\Domain\Apps\InstallationPoller;
use App\Domain\Apps\RecipeSynchronizer;
use App\Enums\AppInstallationStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Installing apps through the guest agent (docs/adr/0015) on the in-memory
 * Proxmox: the install_app task uploads and starts the runner, the poller
 * follows status.json and stores the outputs.
 */
class AppInstallTest extends TestCase
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
        $this->admin = User::factory()->create();
        app(RecipeSynchronizer::class)->sync();
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function install(string $slug = 'wireguard', array $inputs = []): AppInstallation
    {
        return app(AppInstaller::class)->install($this->server, AppRecipe::query()->where('slug', $slug)->firstOrFail(), $inputs, $this->admin, '127.0.0.1')->refresh();
    }

    private function directory(AppInstallation $installation): string
    {
        return '/var/lib/proxmotion/apps/'.$installation->uuid;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function guestWrites(AppInstallation $installation, string $file, array|string $content): void
    {
        $this->proxmox->guestFiles[$this->server->vmid][$this->directory($installation).'/'.$file] = is_array($content) ? json_encode($content, JSON_THROW_ON_ERROR) : $content;
    }

    private function poll(AppInstallation $installation): AppInstallation
    {
        app(InstallationPoller::class)->poll($installation->refresh());

        return $installation->refresh();
    }

    public function test_the_task_uploads_the_recipe_and_starts_the_runner_detached()
    {
        $installation = $this->install(inputs: ['port' => 51900]);
        $files = $this->proxmox->guestFiles[$this->server->vmid];
        $directory = $this->directory($installation);

        $this->assertSame(AppInstallationStatus::Installing, $installation->status);
        $this->assertSame(1, $installation->attempts);
        $this->assertNotNull($installation->started_at);
        $this->assertSame(TaskStatus::Succeeded, $installation->task?->status);
        $this->assertSame(TaskType::InstallApp, $installation->task->type);

        $this->assertSame(file_get_contents(base_path('recipes/wireguard/install.sh')), $files[$directory.'/install.sh']);
        $this->assertSame(file_get_contents(base_path('recipes/_lib/run.sh')), $files[$directory.'/run.sh']);
        $this->assertSame(file_get_contents(base_path('recipes/_lib/proxmotion.sh')), $files[$directory.'/proxmotion.sh']);
        $this->assertSame(['port' => 51900], json_decode($files[$directory.'/inputs.json'], true));
        $this->assertSame(['hostname' => 'web1.example.com', 'primary_ipv4' => '203.0.113.2', 'primary_ipv6' => null, 'os_release' => ['debian', 'ubuntu']], json_decode($files[$directory.'/server.json'], true));
        $this->assertSame('installing', json_decode($files[$directory.'/status.json'], true)['status']);

        $start = collect($this->proxmox->guestCommands)->last();
        $this->assertSame(['systemd-run', '--unit=proxmotion-app-'.$installation->uuid.'-1', '--collect', '--quiet', '/bin/bash', $directory.'/run.sh'], $start['command']);
        $this->assertSame(1, AuditLog::query()->where('action', 'app.install_requested')->count());
    }

    public function test_defaults_fill_in_missing_inputs()
    {
        $installation = $this->install('openvpn');

        $this->assertSame(['port' => 1194, 'protocol' => 'udp'], $installation->inputs);
    }

    public function test_invalid_inputs_are_refused_before_anything_happens()
    {
        try {
            $this->install('openvpn', ['port' => 70000, 'protocol' => 'sctp', 'command' => 'rm -rf /']);
            $this->fail('Invalid inputs were accepted.');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['inputs.port', 'inputs.protocol', 'inputs.command'], array_keys($e->errors()));
        }

        $this->assertSame(0, AppInstallation::query()->count());
        $this->assertSame(1, $this->server->tasks()->count(), 'Only the create task.');
    }

    public function test_progress_is_read_from_the_status_file()
    {
        $installation = $this->install();
        $this->guestWrites($installation, 'status.json', ['status' => 'installing', 'progress' => 50, 'step' => 'Creating keys']);

        $installation = $this->poll($installation);

        $this->assertSame(AppInstallationStatus::Installing, $installation->status);
        $this->assertSame(50, $installation->progress);
        $this->assertSame('Creating keys', $installation->step);
    }

    public function test_an_installed_app_keeps_its_outputs_encrypted()
    {
        $installation = $this->install();
        $this->guestWrites($installation, 'status.json', ['status' => 'installed', 'progress' => 100, 'step' => 'Installed']);
        $this->guestWrites($installation, 'outputs.json', ['endpoint' => '203.0.113.2:51820', 'undeclared' => 'ignored']);
        $this->guestWrites($installation, 'outputs/client.conf', "[Interface]\nPrivateKey = secret\n");

        $installation = $this->poll($installation);

        $this->assertSame(AppInstallationStatus::Installed, $installation->status);
        $this->assertSame(100, $installation->progress);
        $this->assertNotNull($installation->finished_at);
        $this->assertSame([
            'client_config' => ['type' => 'file', 'value' => base64_encode("[Interface]\nPrivateKey = secret\n"), 'filename' => 'client.conf'],
            'endpoint' => ['type' => 'text', 'value' => '203.0.113.2:51820'],
        ], $installation->outputs);

        $raw = (string) DB::table('app_installations')->where('id', $installation->id)->value('outputs');
        $this->assertStringNotContainsString('PrivateKey', $raw);
        $this->assertStringNotContainsString(base64_encode("[Interface]\nPrivateKey = secret\n"), $raw);
    }

    public function test_administrators_see_the_outputs_on_the_server_page_once_asked_and_audited()
    {
        $installation = $this->install();
        $this->guestWrites($installation, 'status.json', ['status' => 'installed', 'progress' => 100, 'step' => 'Installed']);
        $this->guestWrites($installation, 'outputs.json', ['endpoint' => '203.0.113.2:51820']);
        $this->guestWrites($installation, 'outputs/client.conf', "[Interface]\nPrivateKey = secret\n");
        $this->poll($installation);
        $admin = User::factory()->withTwoFactor()->create();

        $this->actingAs($admin)->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('apps.0.has_outputs', true)
                ->where('revealedAppOutputs', null));
        $this->assertSame(0, AuditLog::query()->where('action', 'app.outputs_revealed')->count());

        $this->actingAs($admin)
            ->from(route('admin.servers.show', $this->server))
            ->post(route('admin.app-installations.outputs', $installation))
            ->assertRedirect(route('admin.servers.show', $this->server));

        $this->actingAs($admin)->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('revealedAppOutputs.installation', $installation->uuid)
                ->where('revealedAppOutputs.outputs.1.key', 'endpoint')
                ->where('revealedAppOutputs.outputs.1.value', '203.0.113.2:51820')
                ->where('revealedAppOutputs.outputs.0.filename', 'client.conf')
                ->where('revealedAppOutputs.outputs.0.content_base64', base64_encode("[Interface]\nPrivateKey = secret\n")));

        $log = AuditLog::query()->where('action', 'app.outputs_revealed')->sole();
        $this->assertTrue($log->user?->is($admin) ?? false);
        $this->assertStringNotContainsString('PrivateKey', (string) json_encode($log->properties));
    }

    public function test_a_failed_installer_fails_the_installation_with_its_message()
    {
        $installation = $this->install();
        $this->guestWrites($installation, 'status.json', ['status' => 'failed', 'progress' => 10, 'step' => 'Installing WireGuard', 'message' => 'exit code 100: E: Unable to locate package wireguard']);

        $installation = $this->poll($installation);

        $this->assertSame(AppInstallationStatus::Failed, $installation->status);
        $this->assertSame('exit code 100: E: Unable to locate package wireguard', $installation->error);
        $this->assertSame('Installing WireGuard', $installation->step);
    }

    public function test_an_installer_past_its_timeout_fails_also_when_the_agent_is_gone()
    {
        $installation = $this->install();
        $this->proxmox->agentDown[$this->server->vmid] = true;

        $this->assertSame(AppInstallationStatus::Installing, $this->poll($installation)->status, 'Within the timeout it waits.');

        $this->travel(11)->minutes();

        $installation = $this->poll($installation);
        $this->assertSame(AppInstallationStatus::Failed, $installation->status);
        $this->assertSame('The installation did not finish within 10 minutes.', $installation->error);
    }

    public function test_an_unreachable_agent_fails_the_start()
    {
        $this->proxmox->agentDown[$this->server->vmid] = true;

        $installation = $this->install();

        $this->assertSame(AppInstallationStatus::Failed, $installation->status);
        $this->assertStringContainsString('guest agent did not answer', (string) $installation->error);
        $this->assertSame(TaskStatus::Failed, $installation->task?->status);
    }

    public function test_a_runner_that_cannot_be_started_fails_the_installation()
    {
        $this->proxmox->execHandler = fn (array $command) => $command[0] === 'systemd-run' ? [1, '', 'Failed to start transient service unit: Unit already exists.'] : [0, '', ''];

        $installation = $this->install();

        $this->assertSame(AppInstallationStatus::Failed, $installation->status);
        $this->assertSame('The installer could not be started: Failed to start transient service unit: Unit already exists.', $installation->error);
    }

    public function test_a_directory_that_cannot_be_created_fails_with_the_reason_before_any_file_is_written()
    {
        // AlmaLinux 9 with SELinux enforcing: the agent's commands are confined.
        $this->proxmox->execHandler = fn (array $command) => $command[0] === 'mkdir'
            ? [1, '', "mkdir: cannot create directory '/var/lib/proxmotion': Permission denied\n"]
            : [0, '', ''];

        $installation = $this->install();

        $this->assertSame(AppInstallationStatus::Failed, $installation->status);
        $this->assertStringContainsString("mkdir failed inside VM {$this->server->vmid} (exit code 1): mkdir: cannot create directory '/var/lib/proxmotion': Permission denied", (string) $installation->error);
        $this->assertStringContainsString('SELinux', (string) $installation->error);
        $this->assertSame([], array_filter(
            array_keys($this->proxmox->guestFiles[$this->server->vmid] ?? []),
            fn (string $path) => str_starts_with($path, '/var/lib/proxmotion/'),
        ));
    }

    public function test_a_failed_installation_can_be_retried_with_a_new_unit()
    {
        $installation = $this->install();
        $this->guestWrites($installation, 'status.json', ['status' => 'failed', 'progress' => 10, 'step' => 'x', 'message' => 'boom']);
        $this->poll($installation);

        $installation = app(AppInstaller::class)->retry($installation, $this->admin, '127.0.0.1')->refresh();

        $this->assertSame(AppInstallationStatus::Installing, $installation->status);
        $this->assertSame(2, $installation->attempts);
        $this->assertNull($installation->error);
        $this->assertSame('installing', json_decode($this->proxmox->guestFiles[$this->server->vmid][$this->directory($installation).'/status.json'], true)['status'], 'The old outcome is overwritten.');
        $this->assertSame('--unit=proxmotion-app-'.$installation->uuid.'-2', collect($this->proxmox->guestCommands)->last()['command'][1]);
    }

    public function test_an_installation_keeps_its_recipe_version_also_when_retried()
    {
        $installation = $this->install();
        $original = $installation->app_recipe_version_id;
        $this->guestWrites($installation, 'status.json', ['status' => 'failed', 'progress' => 10, 'step' => 'x', 'message' => 'boom']);
        $this->poll($installation);

        // A newer version arrives before the retry.
        $app = $installation->recipe;
        $newer = $app->current()->replicate()->forceFill(['version' => '9.0.0', 'files' => ['install.sh' => "#!/bin/bash\necho newer\n"]]);
        $newer->save();
        $app->forceFill(['latest_version_id' => $newer->id])->save();

        $installation = app(AppInstaller::class)->retry($installation->refresh(), $this->admin, '127.0.0.1')->refresh();

        $this->assertSame($original, $installation->app_recipe_version_id);
        $this->assertStringNotContainsString('newer', $this->proxmox->guestFiles[$this->server->vmid][$this->directory($installation).'/install.sh']);
    }

    public function test_apps_follow_the_distribution_of_the_servers_template()
    {
        $wireguard = AppRecipe::query()->where('slug', 'wireguard')->firstOrFail();
        $eligibility = app(AppEligibility::class);
        $useTemplate = function (array $attributes) {
            $this->server->forceFill(['os_template_id' => OsTemplate::factory()->create($attributes)->id])->save();
            $this->server->unsetRelation('osTemplate');
        };

        $useTemplate(['name' => 'Rocky 9', 'distribution' => 'rocky', 'version' => '9']);
        $this->assertSame('This app does not support the operating system.', $eligibility->forServer($wireguard, $this->server)?->translatedMessage(), 'Rocky is not in the Debian family.');

        $useTemplate(['name' => 'Debian 13', 'distribution' => 'debian', 'version' => '13']);
        $this->assertNull($eligibility->forServer($wireguard, $this->server), 'Newer than min_versions, not named by version.');

        $useTemplate(['name' => 'Debian 11', 'distribution' => 'debian', 'version' => '11']);
        $this->assertNotNull($eligibility->forServer($wireguard, $this->server), 'Older than min_versions.');
    }

    public function test_apps_are_not_offered_on_versions_newer_than_max_versions()
    {
        $wireguard = AppRecipe::query()->where('slug', 'wireguard')->firstOrFail();
        $version = $wireguard->latestVersion;
        $this->assertNotNull($version);
        $definition = $version->definition;
        $definition['requires']['max_versions'] = ['ubuntu' => '24.04', 'debian' => '12'];
        $version->forceFill(['definition' => $definition])->save();
        $wireguard->unsetRelation('latestVersion');
        $eligibility = app(AppEligibility::class);
        $useTemplate = function (array $attributes) {
            $this->server->forceFill(['os_template_id' => OsTemplate::factory()->create($attributes)->id])->save();
            $this->server->unsetRelation('osTemplate');
        };

        $useTemplate(['name' => 'Debian 13', 'distribution' => 'debian', 'version' => '13']);
        $this->assertSame('This app does not support the operating system.', $eligibility->forServer($wireguard, $this->server)?->translatedMessage());
        $this->assertNotNull($eligibility->forCatalog($wireguard, $this->server->osTemplate, null));

        $useTemplate(['name' => 'Debian 12', 'distribution' => 'debian', 'version' => '12']);
        $this->assertNull($eligibility->forServer($wireguard, $this->server));

        $useTemplate(['name' => 'Debian 12.7', 'distribution' => 'debian', 'version' => '12.7']);
        $this->assertNull($eligibility->forServer($wireguard, $this->server), 'A maximum of 12 covers its point releases.');

        $useTemplate(['name' => 'Ubuntu 24.04', 'distribution' => 'ubuntu', 'version' => '24.04']);
        $this->assertNull($eligibility->forServer($wireguard, $this->server));
    }

    public function test_the_runner_learns_which_operating_systems_to_accept()
    {
        $installation = $this->install();
        $server = json_decode($this->proxmox->guestFiles[$this->server->vmid][$this->directory($installation).'/server.json'], true);

        // run.sh compares them with ID and ID_LIKE of /etc/os-release.
        $this->assertSame(['debian', 'ubuntu'], $server['os_release']);
    }

    public function test_a_distribution_is_matched_as_the_one_it_is_compatible_with()
    {
        $wireguard = AppRecipe::query()->where('slug', 'wireguard')->firstOrFail();
        $version = $wireguard->latestVersion;
        $this->assertNotNull($version);
        $definition = $version->definition;
        $definition['requires']['max_versions'] = ['debian' => '12'];
        $version->forceFill(['definition' => $definition])->save();
        $wireguard->unsetRelation('latestVersion');
        $eligibility = app(AppEligibility::class);
        $useTemplate = function (array $attributes) {
            $this->server->forceFill(['os_template_id' => OsTemplate::factory()->create($attributes)->id])->save();
            $this->server->unsetRelation('osTemplate');
        };

        // Pardus 23.4 is matched as Debian 12, not by its own version (docs/adr/0024).
        $useTemplate(['name' => 'Pardus 23', 'distribution' => 'pardus', 'version' => '23.4', 'compatible_with' => 'debian', 'compatible_version' => '12']);
        $this->assertNull($eligibility->forServer($wireguard, $this->server));

        $useTemplate(['name' => 'Pardus 25', 'distribution' => 'pardus', 'version' => '25.0', 'compatible_with' => 'debian', 'compatible_version' => '13']);
        $this->assertNotNull($eligibility->forServer($wireguard, $this->server), 'The Debian it is compatible with is newer than max_versions.');

        $useTemplate(['name' => 'Unknown', 'distribution' => 'mydistro', 'family' => 'debian', 'version' => '1']);
        $this->assertNotNull($eligibility->forServer($wireguard, $this->server), 'A family covers only the distributions the panel knows.');
    }

    public function test_the_runner_also_accepts_the_templates_own_distribution()
    {
        $this->server->osTemplate->forceFill(['distribution' => 'pardus', 'version' => '23.4', 'compatible_with' => 'debian', 'compatible_version' => '12'])->save();

        $installation = $this->install();
        $server = json_decode($this->proxmox->guestFiles[$this->server->vmid][$this->directory($installation).'/server.json'], true);

        $this->assertSame(['debian', 'ubuntu', 'pardus'], $server['os_release']);
    }

    public function test_one_app_installs_at_a_time_and_an_installed_app_is_not_installed_twice()
    {
        $wireguard = $this->install();

        $this->expectExceptionOnInstall('docker', 'Another app is still being installed on this server.');

        $this->guestWrites($wireguard, 'status.json', ['status' => 'installed']);
        $this->poll($wireguard);

        $this->expectExceptionOnInstall('wireguard', 'This app is already installed on the server.');
        $this->assertSame(AppInstallationStatus::Installing, $this->install('docker')->status);
    }

    private function expectExceptionOnInstall(string $slug, string $message): void
    {
        try {
            $this->install($slug);
            $this->fail("{$slug} was installed.");
        } catch (AppInstallConflict $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_apps_that_do_not_fit_the_server_are_refused()
    {
        $this->server->forceFill(['memory_mib' => 768])->save();

        try {
            $this->install('docker');
            $this->fail('Docker was installed with 768 MiB.');
        } catch (AppNotEligible $e) {
            $this->assertSame('This app needs at least 1024 MiB of memory.', $e->translatedMessage());
        }

        $this->server->forceFill(['memory_mib' => 4096, 'os_template_id' => OsTemplate::factory()->windows()->create()->id])->save();
        $this->server->unsetRelation('osTemplate');

        $this->expectException(AppNotEligible::class);
        $this->expectExceptionMessage('This app does not support the operating system.');
        $this->install('docker');
    }

    public function test_inactive_apps_and_paid_apps_without_entitlement_cannot_be_installed()
    {
        AppRecipe::query()->where('slug', 'docker')->update(['is_active' => false]);
        $paid = AppRecipe::factory()->withDefinition(['billing' => 'paid'])->create();

        foreach (['docker' => 'This app is not available.', $paid->slug => 'This app has to be bought for the server first.'] as $slug => $message) {
            try {
                $this->install($slug);
                $this->fail("{$slug} was installed.");
            } catch (AppNotEligible $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_reinstalling_the_server_removes_its_apps()
    {
        $installed = $this->install();
        $this->guestWrites($installed, 'status.json', ['status' => 'installed']);
        $this->poll($installed);
        $running = $this->install('docker');

        app(TaskRunner::class)->start($this->server, TaskType::Reinstall);

        $this->assertSame(AppInstallationStatus::Removed, $installed->refresh()->status);
        $this->assertSame(AppInstallationStatus::Removed, $running->refresh()->status);
        $this->assertSame('The server was reinstalled.', $running->error);
    }
}
