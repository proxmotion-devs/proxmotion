<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\AppInstallConflict;
use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppNotEligible;
use App\Domain\Apps\AppRunner;
use App\Domain\Apps\InstallationPoller;
use App\Domain\Apps\RunPoller;
use App\Enums\AppInstallationStatus;
use App\Enums\AppRunKind;
use App\Enums\AppRunStatus;
use App\Enums\TaskType;
use App\Jobs\PollAppRun;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AppRun;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Concerns\OffersApps;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Uninstalls and actions of installed apps (docs/adr/0016 §4) on the
 * in-memory Proxmox: the app_run task uploads the recipe version and starts
 * `run.sh <script> <run directory>`, the poller follows the run's status.json.
 */
class AppRunTest extends TestCase
{
    use BuildsInfrastructure, OffersApps, RefreshDatabase;

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
        $this->syncRecipes();
        $this->offerApps();
    }

    private function installed(string $slug = 'wireguard'): AppInstallation
    {
        $installation = app(AppInstaller::class)->install($this->server, AppRecipe::query()->where('slug', $slug)->firstOrFail(), [], $this->admin, '127.0.0.1');
        $this->write($installation->guestDirectory().'/status.json', ['status' => 'installed']);
        app(InstallationPoller::class)->poll($installation->refresh());

        return $installation->refresh();
    }

    /**
     * @param  array<string, mixed>|string  $content
     */
    private function write(string $path, array|string $content): void
    {
        $this->proxmox->guestFiles[$this->server->vmid][$path] = is_array($content) ? json_encode($content, JSON_THROW_ON_ERROR) : $content;
    }

    private function poll(AppRun $run): AppRun
    {
        app(RunPoller::class)->poll($run->refresh());

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function action(AppInstallation $installation, string $key, array $inputs = []): AppRun
    {
        return app(AppRunner::class)->action($installation, $key, $inputs, $this->admin, '127.0.0.1')->refresh();
    }

    public function test_an_uninstall_runs_the_installations_script_in_its_own_directory()
    {
        $installation = $this->installed();
        $run = app(AppRunner::class)->uninstall($installation, $this->admin, '127.0.0.1')->refresh();
        $files = $this->proxmox->guestFiles[$this->server->vmid];
        $app = $installation->guestDirectory();
        $directory = $run->guestDirectory();

        $this->assertSame(AppRunKind::Uninstall, $run->kind);
        $this->assertSame(AppRunStatus::Running, $run->status);
        $this->assertSame(TaskType::AppRun, $run->task?->type);
        $this->assertSame(AppInstallationStatus::Uninstalling, $installation->refresh()->status);
        $this->assertSame($app.'/runs/'.$run->uuid, $directory);

        $this->assertSame(file_get_contents(base_path('tests/Fixtures/recipes/wireguard/uninstall.sh')), $files[$app.'/uninstall.sh']);
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/recipes/wireguard/lib.sh')), $files[$app.'/lib.sh'], 'Includes are written too.');
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/recipes/wireguard/actions/add-client.sh')), $files[$app.'/actions/add-client.sh']);
        $this->assertSame('{}', trim($files[$directory.'/inputs.json']));
        $this->assertSame('installing', json_decode($files[$directory.'/status.json'], true)['status']);

        $start = collect($this->proxmox->guestCommands)->last();
        $this->assertSame(['systemd-run', '--unit=proxmotion-app-run-'.$run->uuid, '--collect', '--quiet', '/bin/bash', $app.'/run.sh', 'uninstall.sh', $directory], $start['command']);
        $this->assertSame(1, AuditLog::query()->where('action', 'app.uninstall_requested')->count());
    }

    public function test_a_finished_uninstall_removes_the_installation_its_outputs_and_its_directory()
    {
        $installation = $this->installed();
        $run = app(AppRunner::class)->uninstall($installation, $this->admin, '127.0.0.1');
        $this->write($run->guestDirectory().'/status.json', ['status' => 'succeeded', 'progress' => 100, 'step' => 'Done']);

        $run = $this->poll($run);

        $this->assertSame(AppRunStatus::Succeeded, $run->status);
        $installation->refresh();
        $this->assertSame(AppInstallationStatus::Removed, $installation->status);
        $this->assertNull($installation->outputs);
        $this->assertSame(['rm', '-rf', '--one-file-system', $installation->guestDirectory()], collect($this->proxmox->guestCommands)->last()['command']);

        $this->assertSame(AppInstallationStatus::Installing, app(AppInstaller::class)->install($this->server, $installation->recipe, [], $this->admin, null)->refresh()->status, 'The app can be installed again.');
    }

    public function test_a_failed_uninstall_can_be_tried_again()
    {
        $installation = $this->installed();
        $run = app(AppRunner::class)->uninstall($installation, $this->admin, '127.0.0.1');
        $this->write($run->guestDirectory().'/status.json', ['status' => 'failed', 'progress' => 20, 'step' => 'Stopping the VPN', 'message' => 'systemctl failed']);

        $this->assertSame('systemctl failed', $this->poll($run)->error);
        $this->assertSame(AppInstallationStatus::UninstallFailed, $installation->refresh()->status);

        try {
            app(AppInstaller::class)->install($this->server, $installation->recipe, [], $this->admin, null);
            $this->fail('The app was installed twice.');
        } catch (AppInstallConflict $e) {
            $this->assertSame('This app is already installed on the server.', $e->getMessage());
        }

        $again = app(AppRunner::class)->uninstall($installation->refresh(), $this->admin, '127.0.0.1');
        $this->assertSame(AppRunStatus::Running, $again->refresh()->status);
    }

    public function test_apps_without_an_uninstall_script_cannot_be_uninstalled()
    {
        $app = AppRecipe::factory()->create();
        $installation = AppInstallation::factory()->for($this->server)->for($app, 'recipe')->create();

        $this->expectException(AppNotEligible::class);
        $this->expectExceptionMessage('This app cannot be uninstalled.');

        app(AppRunner::class)->uninstall($installation, $this->admin, null);
    }

    public function test_an_action_runs_with_its_validated_inputs_and_keeps_its_outputs_encrypted()
    {
        $installation = $this->installed();

        try {
            $this->action($installation, 'add_client', ['name' => 'Not A Slug']);
            $this->fail('An invalid name was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inputs.name', $e->errors());
        }

        $run = $this->action($installation, 'add_client', ['name' => 'laptop']);
        $directory = $run->guestDirectory();

        $this->assertSame(['name' => 'laptop'], json_decode($this->proxmox->guestFiles[$this->server->vmid][$directory.'/inputs.json'], true));
        $this->assertSame('actions/add-client.sh', collect($this->proxmox->guestCommands)->last()['command'][6]);
        $this->assertSame(AppInstallationStatus::Installed, $installation->refresh()->status, 'Actions leave the installation alone.');

        $this->write($directory.'/outputs/client.conf', "[Interface]\nPrivateKey = laptop-secret\n");
        $this->write($directory.'/status.json', ['status' => 'succeeded']);
        $run = $this->poll($run);

        $this->assertSame(AppRunStatus::Succeeded, $run->status);
        $this->assertSame(['client_config' => ['type' => 'file', 'value' => base64_encode("[Interface]\nPrivateKey = laptop-secret\n"), 'filename' => 'client.conf']], $run->outputs);
        $this->assertStringNotContainsString('laptop-secret', (string) DB::table('app_runs')->value('outputs'));
        $this->assertStringNotContainsString('laptop', (string) DB::table('app_runs')->value('inputs'));
        $this->assertSame(1, AuditLog::query()->where('action', 'app.action_requested')->count());
    }

    public function test_actions_need_an_installed_app_a_known_key_and_one_run_at_a_time()
    {
        $installation = $this->installed();

        try {
            $this->action($installation, 'reboot_the_moon');
            $this->fail('An unknown action ran.');
        } catch (AppNotEligible $e) {
            $this->assertSame('This app has no action :action.', $e->getMessage());
        }

        $this->action($installation, 'list_clients');

        try {
            $this->action($installation, 'list_clients');
            $this->fail('Two runs at once.');
        } catch (AppInstallConflict $e) {
            $this->assertSame('Another action is still running for this app.', $e->getMessage());
        }

        $failed = AppInstallation::factory()->for($this->server)->for(AppRecipe::query()->where('slug', 'openvpn')->sole(), 'recipe')->create(['status' => AppInstallationStatus::Failed]);
        $this->expectException(AppInstallConflict::class);
        $this->action($failed, 'list_clients');
    }

    public function test_a_run_past_its_timeout_fails_also_when_the_agent_is_gone()
    {
        $run = $this->action($this->installed(), 'list_clients');
        $run->forceFill(['started_at' => now()->subMinutes(2)])->save();
        $this->proxmox->agentDown[$this->server->vmid] = true;

        $run = $this->poll($run);

        $this->assertSame(AppRunStatus::Failed, $run->status);
        $this->assertSame('The script did not finish within 1 minutes.', $run->error);
    }

    public function test_reinstalling_the_server_ends_its_runs()
    {
        $run = $this->action($this->installed(), 'list_clients');

        app(TaskRunner::class)->start($this->server, TaskType::Reinstall);

        $this->assertSame(AppRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(AppInstallationStatus::Removed, $run->installation->refresh()->status);
    }

    public function test_apps_poll_follows_running_runs()
    {
        $run = $this->action($this->installed(), 'list_clients');
        Queue::fake();

        $this->artisan('apps:poll')->assertSuccessful();

        Queue::assertPushed(PollAppRun::class, fn (PollAppRun $job) => $job->run->is($run));
    }
}
