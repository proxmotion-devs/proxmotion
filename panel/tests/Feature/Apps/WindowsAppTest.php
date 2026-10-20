<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\AppEligibility;
use App\Domain\Apps\AppGuest;
use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppLogs;
use App\Domain\Apps\AppRunner;
use App\Domain\Apps\InstallationPoller;
use App\Domain\Apps\RecipeSynchronizer;
use App\Domain\Apps\RunPoller;
use App\Domain\Servers\GuestScripts;
use App\Enums\AppInstallationStatus;
use App\Enums\AppRunStatus;
use App\Enums\OsPlatform;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AppRun;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Concerns\OffersApps;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Windows apps (docs/adr/0029) on the in-memory Proxmox: the same contract as
 * on Linux, with the PowerShell runner started by a scheduled task in
 * C:/ProgramData/Proxmotion/apps.
 */
class WindowsAppTest extends TestCase
{
    use BuildsInfrastructure, OffersApps, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->osTemplate->forceFill(['name' => 'Windows Server 2022', 'distribution' => 'windows', 'version' => '2022'])->save();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        // Windows setup has finished as soon as the server is created.
        $this->proxmox->execHandler = fn (array $command) => $command === GuestScripts::WINDOWS_SETUP_COMMAND
            ? [0, "IMAGE_STATE_COMPLETE\r\nStopped\r\n", '']
            : [0, '', ''];
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->admin = User::factory()->create();
        app(RecipeSynchronizer::class)->sync();
        $this->offerApps();
    }

    private function app(string $slug): AppRecipe
    {
        return AppRecipe::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function install(string $slug = 'sqlserver-express', array $inputs = []): AppInstallation
    {
        return app(AppInstaller::class)->install($this->server, $this->app($slug), $inputs, $this->admin, '127.0.0.1')->refresh();
    }

    private function write(string $file, string $content): void
    {
        $this->proxmox->guestFiles[$this->server->vmid][$file] = $content;
    }

    /**
     * @return list<list<string>>
     */
    private function commands(): array
    {
        return array_map(fn (array $entry) => $entry['command'], $this->proxmox->guestCommands);
    }

    public function test_windows_apps_are_offered_on_windows_templates_only()
    {
        $eligibility = app(AppEligibility::class);
        $linux = OsTemplate::factory()->create();

        $this->assertSame(OsPlatform::Windows, $this->osTemplate->refresh()->platform);

        foreach (['iis', 'sqlserver-express', 'chocolatey'] as $slug) {
            $this->assertNull($eligibility->forCatalog($this->app($slug)->load('latestVersion'), $this->osTemplate, $this->package), $slug);
            $this->assertNotNull($eligibility->forCatalog($this->app($slug)->load('latestVersion'), $linux, $this->package), $slug);
        }

        $this->assertNotNull($eligibility->forCatalog($this->app('wireguard')->load('latestVersion'), $this->osTemplate, $this->package), 'A Linux app on Windows.');
    }

    public function test_a_trial_never_puts_a_windows_draft_on_a_linux_server()
    {
        $eligibility = app(AppEligibility::class);
        $this->server->osTemplate->forceFill(['distribution' => 'ubuntu', 'version' => '24.04'])->save();
        $app = $this->app('iis');

        $problem = $eligibility->forTrial($app, $app->latestVersion, $this->server->refresh());

        $this->assertSame('This app does not support the operating system.', $problem?->getMessage());
    }

    public function test_the_task_uploads_the_powershell_runner_and_starts_it_as_a_scheduled_task()
    {
        $installation = $this->install(inputs: ['port' => 1500]);
        $files = $this->proxmox->guestFiles[$this->server->vmid];
        $directory = 'C:/ProgramData/Proxmotion/apps/'.$installation->uuid;

        $this->assertSame(AppInstallationStatus::Installing, $installation->status);
        $this->assertSame(OsPlatform::Windows, $installation->platform());
        $this->assertSame($directory, $installation->guestDirectory());

        // .ps1 files get a byte order mark for Windows PowerShell 5.1; JSON does not.
        $this->assertSame("\u{FEFF}".file_get_contents(base_path('recipes/sqlserver-express/install.ps1')), $files[$directory.'/install.ps1']);
        $this->assertSame("\u{FEFF}".file_get_contents(base_path('recipes/sqlserver-express/lib.ps1')), $files[$directory.'/lib.ps1']);

        foreach (['proxmotion.ps1', 'run.ps1', 'start.ps1', 'read-log.ps1'] as $library) {
            $this->assertSame("\u{FEFF}".file_get_contents(base_path('recipes/_lib/'.$library)), $files[$directory.'/'.$library]);
        }

        $this->assertArrayNotHasKey($directory.'/run.sh', $files);
        $this->assertSame(['port' => 1500], json_decode($files[$directory.'/inputs.json'], true));
        $this->assertStringStartsWith('{', $files[$directory.'/server.json']);

        $commands = $this->commands();
        $this->assertContains(['cmd.exe', '/c', 'mkdir', 'C:\\ProgramData\\Proxmotion\\apps'], $commands);
        $this->assertContains(['icacls', 'C:\\ProgramData\\Proxmotion\\apps', '/inheritance:r', '/grant:r', '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', '/Q'], $commands);
        $this->assertContains(['cmd.exe', '/c', 'mkdir', 'C:\\ProgramData\\Proxmotion\\apps\\'.$installation->uuid, 'C:\\ProgramData\\Proxmotion\\apps\\'.$installation->uuid.'\\actions'], $commands);
        $this->assertSame([
            'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $directory.'/start.ps1',
            '-Task', 'proxmotion-app-'.$installation->uuid.'-1', '-Script', 'install.ps1', '-RunDir', $directory, '-Install',
        ], end($commands));
    }

    public function test_the_poller_reads_status_and_outputs_also_with_a_byte_order_mark()
    {
        $installation = $this->install();
        $directory = $installation->guestDirectory();
        $this->write($directory.'/status.json', "\u{FEFF}".'{"status":"installed","progress":100,"step":"Installed"}');
        $this->write($directory.'/outputs.json', "\u{FEFF}".'{"server":"203.0.113.2,1433","username":"sa","password":"Generated1pass"}');

        app(InstallationPoller::class)->poll($installation);
        $installation->refresh();

        $this->assertSame(AppInstallationStatus::Installed, $installation->status);
        $this->assertSame('sa', $installation->outputs['username']['value'] ?? null);
        $this->assertSame('Generated1pass', $installation->outputs['password']['value'] ?? null);
    }

    public function test_the_log_is_read_with_the_powershell_reader()
    {
        $installation = $this->install();
        $directory = $installation->guestDirectory();
        $this->write($directory.'/run.log', "==> [10:00:00] Preparing\n==> [10:00:01] Downloading SQL Server 2022 Express\n");
        $this->write($directory.'/status.json', '{"status":"installing","progress":10,"step":"Downloading SQL Server 2022 Express"}');

        $log = app(AppLogs::class)->current($installation);
        $this->assertNotNull($log);
        app(AppLogs::class)->follow($log);

        $this->assertSame(1, $this->proxmox->guestLogReads);
        $this->assertStringContainsString('Downloading SQL Server 2022 Express', (string) $log->refresh()->content);
        $this->assertSame(
            ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $directory.'/read-log.ps1', $directory.'/run.log', '0', (string) AppLogs::CHUNK_BYTES],
            AppGuest::readCommand(OsPlatform::Windows, $directory, $directory.'/run.log', 0, AppLogs::CHUNK_BYTES),
        );
    }

    private function installed(): AppInstallation
    {
        $installation = $this->install();
        $this->write($installation->guestDirectory().'/status.json', '{"status":"installed","progress":100,"step":"Installed"}');
        app(InstallationPoller::class)->poll($installation);

        return $installation->refresh();
    }

    public function test_an_action_runs_in_its_own_directory()
    {
        $installation = $this->installed();
        $run = app(AppRunner::class)->action($installation, 'reset_password', [], $this->admin, '127.0.0.1')->refresh();
        $app = $installation->guestDirectory();
        $directory = $run->guestDirectory();
        $commands = $this->commands();

        $this->assertSame(AppRunStatus::Running, $run->status);
        $this->assertSame($app.'/runs/'.$run->uuid, $directory);
        $this->assertContains(['cmd.exe', '/c', 'mkdir', AppGuest::windowsPath($directory)], $commands);
        $this->assertSame([
            'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $app.'/start.ps1',
            '-Task', 'proxmotion-app-run-'.$run->uuid, '-Script', 'actions/reset-password.ps1', '-RunDir', $directory,
        ], end($commands));

        $this->write($directory.'/status.json', '{"status":"succeeded","progress":100,"step":"Done"}');
        $this->write($directory.'/outputs.json', '{"username":"sa","password":"New1password"}');
        app(RunPoller::class)->poll($run);

        $this->assertSame(AppRunStatus::Succeeded, $run->refresh()->status);
        $this->assertSame('New1password', $run->outputs['password']['value'] ?? null);
    }

    public function test_a_finished_uninstall_removes_the_directory_with_rmdir()
    {
        $installation = $this->installed();
        $run = app(AppRunner::class)->uninstall($installation, $this->admin, '127.0.0.1')->refresh();
        $this->assertSame('-Script', end($this->proxmox->guestCommands)['command'][9]);
        $this->assertSame('uninstall.ps1', end($this->proxmox->guestCommands)['command'][10]);

        $this->write($run->guestDirectory().'/status.json', '{"status":"succeeded","progress":100,"step":"Done"}');
        app(RunPoller::class)->poll($run);

        $this->assertSame(AppInstallationStatus::Removed, $installation->refresh()->status);
        $this->assertSame(['cmd.exe', '/c', 'rmdir', '/s', '/q', 'C:\\ProgramData\\Proxmotion\\apps\\'.$installation->uuid], end($this->proxmox->guestCommands)['command']);
        $this->assertInstanceOf(AppRun::class, $run);
    }
}
