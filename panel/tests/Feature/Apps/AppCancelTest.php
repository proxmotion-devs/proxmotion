<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\AppCanceller;
use App\Domain\Apps\AppGuest;
use App\Domain\Apps\AppInstallConflict;
use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppRunner;
use App\Domain\Apps\InstallationPoller;
use App\Domain\Apps\RunPoller;
use App\Enums\AppInstallationStatus;
use App\Enums\AppRunStatus;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Concerns\OffersApps;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Stopping app scripts inside the server (docs/adr/0030): cancels by an
 * administrator, timeouts, and earlier scripts stopped before a new one starts.
 */
class AppCancelTest extends TestCase
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
        $this->admin = User::factory()->withTwoFactor()->create();
        $this->syncRecipes();
        $this->offerApps();
    }

    private function install(): AppInstallation
    {
        return app(AppInstaller::class)->install($this->server, AppRecipe::query()->where('slug', 'wireguard')->firstOrFail(), [], $this->admin, '127.0.0.1')->refresh();
    }

    private function installed(): AppInstallation
    {
        $installation = $this->install();
        $this->guestStatus($installation->guestDirectory(), 'installed');
        app(InstallationPoller::class)->poll($installation);

        return $installation->refresh();
    }

    private function guestStatus(string $directory, string $status): void
    {
        $this->proxmox->guestFiles[$this->server->vmid][$directory.'/status.json'] = json_encode(['status' => $status, 'progress' => 10, 'step' => 'x', 'message' => 'boom'], JSON_THROW_ON_ERROR);
    }

    /**
     * The names each stop command stopped, in order.
     *
     * @return list<list<string>>
     */
    private function stops(): array
    {
        return collect($this->proxmox->guestCommands)
            ->map(fn (array $entry) => $entry['command'])
            ->filter(fn (array $command) => ($command[3] ?? null) === 'proxmotion-stop')
            ->map(fn (array $command) => array_slice($command, 4))
            ->values()
            ->all();
    }

    public function test_cancelling_fails_the_installation_at_once_and_stops_its_installer()
    {
        $installation = $this->install();
        $this->assertSame([], $this->stops(), 'A first attempt has nothing to stop before it.');

        app(AppCanceller::class)->cancelInstallation($installation, $this->admin, '127.0.0.1');

        $installation->refresh();
        $this->assertSame(AppInstallationStatus::Failed, $installation->status);
        $this->assertSame('Cancelled by an administrator.', $installation->error);
        $this->assertSame([['proxmotion-app-'.$installation->uuid.'-1']], $this->stops());
        $this->assertTrue(AuditLog::query()->where('action', 'app.install_cancelled')->where('user_id', $this->admin->id)->exists());

        $stop = collect($this->proxmox->guestCommands)->last()['command'];
        $this->assertSame(['/bin/bash', '-c', file_get_contents(base_path('recipes/_lib/stop.sh'))], array_slice($stop, 0, 3), 'The script is the command; no file is written.');
    }

    public function test_a_cancel_also_works_while_the_agent_does_not_answer()
    {
        $installation = $this->install();
        $this->proxmox->agentDown[$this->server->vmid] = true;

        app(AppCanceller::class)->cancelInstallation($installation, $this->admin, '127.0.0.1');

        $this->assertSame(AppInstallationStatus::Failed, $installation->refresh()->status, 'The stop is tried again later; the next start stops it too.');
    }

    public function test_only_a_running_installation_can_be_cancelled()
    {
        $installation = $this->installed();

        $this->expectException(AppInstallConflict::class);
        $this->expectExceptionMessage('Only a running installation can be cancelled.');

        app(AppCanceller::class)->cancelInstallation($installation, $this->admin, '127.0.0.1');
    }

    public function test_a_late_status_does_not_undo_a_cancel()
    {
        $installation = $this->install();
        app(AppCanceller::class)->cancelInstallation($installation, $this->admin, '127.0.0.1');
        $this->guestStatus($installation->guestDirectory(), 'installed');

        app(InstallationPoller::class)->poll($installation);

        $this->assertSame(AppInstallationStatus::Failed, $installation->refresh()->status);
    }

    public function test_a_cancelled_uninstall_leaves_the_installation_uninstall_failed()
    {
        $installation = $this->installed();
        $run = app(AppRunner::class)->uninstall($installation, $this->admin, '127.0.0.1')->refresh();
        $this->assertSame([['proxmotion-app-'.$installation->uuid.'-1']], $this->stops(), 'The installer is stopped before the uninstall starts.');

        app(AppCanceller::class)->cancelRun($run, $this->admin, '127.0.0.1');

        $this->assertSame(AppRunStatus::Failed, $run->refresh()->status);
        $this->assertSame('Cancelled by an administrator.', $run->error);
        $this->assertSame(AppInstallationStatus::UninstallFailed, $installation->refresh()->status);
        $this->assertSame(['proxmotion-app-run-'.$run->uuid], $this->stops()[1]);
        $this->assertTrue(AuditLog::query()->where('action', 'app.run_cancelled')->exists());
    }

    public function test_only_a_running_script_can_be_cancelled()
    {
        $installation = $this->installed();
        $run = app(AppRunner::class)->action($installation, 'list_clients', [], $this->admin, '127.0.0.1')->refresh();
        $this->guestStatus($run->guestDirectory(), 'succeeded');
        app(RunPoller::class)->poll($run);

        $this->expectException(AppInstallConflict::class);
        $this->expectExceptionMessage('Only a running script can be cancelled.');

        app(AppCanceller::class)->cancelRun($run->refresh(), $this->admin, '127.0.0.1');
    }

    public function test_an_installer_past_its_timeout_is_stopped()
    {
        $installation = $this->install();
        $this->travel(11)->minutes();

        app(InstallationPoller::class)->poll($installation);

        $this->assertSame(AppInstallationStatus::Failed, $installation->refresh()->status);
        $this->assertSame([['proxmotion-app-'.$installation->uuid.'-1']], $this->stops());
    }

    public function test_a_run_past_its_timeout_is_stopped()
    {
        $installation = $this->installed();
        $run = app(AppRunner::class)->action($installation, 'list_clients', [], $this->admin, '127.0.0.1')->refresh();
        $this->travel(30)->minutes();

        app(RunPoller::class)->poll($run);

        $this->assertSame(AppRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(['proxmotion-app-run-'.$run->uuid], collect($this->stops())->last());
    }

    public function test_a_retry_stops_the_earlier_attempt_before_it_uploads_anything()
    {
        $installation = $this->install();
        $this->guestStatus($installation->guestDirectory(), 'failed');
        app(InstallationPoller::class)->poll($installation);
        $before = count($this->proxmox->guestCommands);

        app(AppInstaller::class)->retry($installation->refresh(), $this->admin, '127.0.0.1');

        $commands = array_column(array_slice($this->proxmox->guestCommands, $before), 'command');
        $this->assertSame(['proxmotion-app-'.$installation->uuid.'-1'], array_slice($commands[0], 4), 'Stopped first.');
        $this->assertSame('--unit='.AppGuest::installName($installation->uuid, 2), end($commands)[1]);
    }

    public function test_nothing_starts_while_an_earlier_script_keeps_running()
    {
        $installation = $this->install();
        $this->guestStatus($installation->guestDirectory(), 'failed');
        app(InstallationPoller::class)->poll($installation);
        $this->proxmox->execHandler = fn (array $command) => ($command[3] ?? null) === 'proxmotion-stop'
            ? [1, '', $command[4]." is still running.\n"]
            : [0, '', ''];

        app(AppInstaller::class)->retry($installation->refresh(), $this->admin, '127.0.0.1');

        $installation->refresh();
        $this->assertSame(AppInstallationStatus::Failed, $installation->status);
        $this->assertSame('A previous script of this app is still running inside the server: proxmotion-app-'.$installation->uuid.'-1 is still running.', $installation->error);
        $this->assertSame(1, $installation->attempts, 'No new attempt was started.');
    }

    public function test_administrators_cancel_from_the_server_page()
    {
        $installation = $this->install();

        $this->actingAs($this->admin)
            ->from(route('admin.servers.show', $this->server))
            ->post(route('admin.app-installations.cancel', $installation))
            ->assertRedirect(route('admin.servers.show', $this->server));

        $this->assertSame(AppInstallationStatus::Failed, $installation->refresh()->status);

        $this->actingAs($this->admin)->post(route('admin.app-installations.cancel', $installation))
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.message', 'Only a running installation can be cancelled.');
    }

    public function test_administrators_cancel_a_running_action()
    {
        $installation = $this->installed();
        $run = app(AppRunner::class)->action($installation, 'list_clients', [], $this->admin, '127.0.0.1')->refresh();

        $this->actingAs($this->admin)->post(route('admin.app-runs.cancel', $run))->assertRedirect();

        $this->assertSame(AppRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(AppInstallationStatus::Installed, $installation->refresh()->status, 'An action leaves the installation alone.');
    }
}
