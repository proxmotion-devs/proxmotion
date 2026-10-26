<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\AppInstallConflict;
use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppNotEligible;
use App\Domain\Apps\AppRunner;
use App\Domain\Apps\AppUpgrades;
use App\Domain\Apps\InstallationPoller;
use App\Domain\Apps\RunPoller;
use App\Enums\AppInstallationStatus;
use App\Enums\AppRunKind;
use App\Enums\AppRunStatus;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AppRun;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Concerns\OffersApps;
use Tests\Concerns\PublishesRecipeVersions;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Upgrades of installed apps (docs/adr/0016 §4) on the in-memory Proxmox:
 * the app_run task writes the new version with its upgrade script, the
 * poller moves the installation to it when the script succeeded.
 */
class AppUpgradeTest extends TestCase
{
    use BuildsInfrastructure, OffersApps, PublishesRecipeVersions, RefreshDatabase;

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
        $installation = app(AppInstaller::class)->install($this->server, AppRecipe::query()->where('slug', $slug)->firstOrFail(), ['port' => 51000], $this->admin, '127.0.0.1');
        $directory = $installation->guestDirectory();
        $this->write($directory.'/status.json', ['status' => 'installed']);
        $this->write($directory.'/outputs.json', ['endpoint' => '203.0.113.2:51000']);
        $this->write($directory.'/outputs/client.conf', "[Interface]\nPrivateKey = first\n");
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

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function upgrade(AppInstallation $installation, array $inputs = []): AppRun
    {
        return app(AppRunner::class)->upgrade($installation->refresh(), $inputs, $this->admin, '127.0.0.1')->refresh();
    }

    private function finish(AppRun $run, string $status = 'succeeded'): AppRun
    {
        $this->write($run->guestDirectory().'/status.json', ['status' => $status, 'progress' => 100, 'step' => 'Done', 'message' => $status === 'failed' ? 'apt failed' : '']);
        app(RunPoller::class)->poll($run->refresh());

        return $run->refresh();
    }

    public function test_an_upgrade_is_offered_for_a_newer_version_whose_range_contains_the_installed_one()
    {
        $installation = $this->installed();
        $upgrades = app(AppUpgrades::class);
        $target = fn () => $upgrades->target($installation->refresh()->load('recipe.latestVersion'));

        $this->assertNull($target(), 'Nothing newer yet.');

        $this->publishVersion('wireguard', '1.4.0', from: '>=1.0.0 <1.3.1');
        $this->assertNull($target(), 'The range leaves out 1.3.1.');

        $this->publishVersion('wireguard', '1.4.1', from: null);
        $this->assertNull($target(), 'No upgrade script.');

        $version = $this->publishVersion('wireguard', '1.5.0', from: '>=1.2.0 <1.5.0');
        $this->assertTrue($version->is($target()));
        $this->assertSame(['version' => '1.5.0', 'inputs' => []], $upgrades->offer($installation->refresh()));

        $this->publishVersion('wireguard', '1.6.0', changes: ['resources' => ['min_memory_mib' => 65536]]);
        $this->assertNull($target(), 'The new version needs more memory than the server has.');
    }

    public function test_an_upgrade_runs_the_new_versions_script_with_the_installed_inputs()
    {
        $installation = $this->installed();
        $old = $installation->version;
        $target = $this->publishVersion('wireguard', '1.4.0', files: ['install.sh' => "#!/usr/bin/env bash\necho new install\n"]);

        $run = $this->upgrade($installation);
        $files = $this->proxmox->guestFiles[$this->server->vmid];
        $app = $installation->guestDirectory();

        $this->assertSame(AppRunKind::Upgrade, $run->kind);
        $this->assertSame(AppRunStatus::Running, $run->status);
        $this->assertTrue($target->is($run->targetVersion));
        $this->assertSame(['port' => 51000], $run->inputs);
        $this->assertSame(AppInstallationStatus::Upgrading, $installation->refresh()->status);
        $this->assertTrue($old->is($installation->version), 'The version changes only when the upgrade succeeded.');
        $this->assertSame(7 * 60, (int) $run->started_at?->diffInSeconds($run->deadline()), 'The upgrade step\'s timeout.');

        $this->assertStringContainsString('echo upgraded', $files[$app.'/upgrade.sh']);
        $this->assertStringContainsString('echo new install', $files[$app.'/install.sh'], 'The new version\'s scripts are written.');
        $this->assertSame(['port' => 51000], json_decode($files[$app.'/inputs.json'], true));
        $this->assertSame(['/bin/bash', $app.'/run.sh', 'upgrade.sh', $run->guestDirectory()], array_slice(collect($this->proxmox->guestCommands)->last()['command'], 4));

        $log = AuditLog::query()->where('action', 'app.upgrade_requested')->sole();
        $this->assertSame(['from' => '1.3.1', 'to' => '1.4.0'], array_intersect_key($log->properties ?? [], ['from' => 1, 'to' => 1]));
    }

    public function test_a_finished_upgrade_moves_the_installation_to_the_new_version_and_merges_its_outputs()
    {
        $installation = $this->installed();
        $target = $this->publishVersion('wireguard', '1.4.0');
        $run = $this->upgrade($installation);
        $this->write($run->guestDirectory().'/outputs.json', ['endpoint' => '203.0.113.2:51001']);

        $run = $this->finish($run);

        $this->assertSame(AppRunStatus::Succeeded, $run->status);
        $this->assertSame([], $run->outputs, 'Outputs belong to the installation.');
        $installation->refresh();
        $this->assertSame(AppInstallationStatus::Installed, $installation->status);
        $this->assertTrue($target->is($installation->version));
        $this->assertSame('203.0.113.2:51001', $installation->outputs['endpoint']['value'] ?? null);
        $this->assertSame(base64_encode("[Interface]\nPrivateKey = first\n"), $installation->outputs['client_config']['value'] ?? null, 'Outputs the upgrade did not write are kept.');
        $this->assertNull(app(AppUpgrades::class)->target($installation));

        // Uninstalls and actions now use the new version's scripts.
        $uninstall = app(AppRunner::class)->uninstall($installation, $this->admin, null)->refresh();
        $this->assertSame('uninstall.sh', $uninstall->script());
        $this->assertTrue($target->is($uninstall->installation->version));
    }

    public function test_a_failed_upgrade_keeps_the_old_version_and_can_be_tried_again()
    {
        $installation = $this->installed();
        $old = $installation->version;
        $this->publishVersion('wireguard', '1.4.0');

        $run = $this->finish($this->upgrade($installation), 'failed');

        $this->assertSame(AppRunStatus::Failed, $run->status);
        $installation->refresh();
        $this->assertSame(AppInstallationStatus::UpgradeFailed, $installation->status);
        $this->assertSame('apt failed', $installation->error);
        $this->assertTrue($old->is($installation->version));
        $this->assertSame('203.0.113.2:51000', $installation->outputs['endpoint']['value'] ?? null);

        try {
            app(AppRunner::class)->action($installation, 'list_clients', [], $this->admin, null);
            $this->fail('Actions need an installed app.');
        } catch (AppInstallConflict) {
        }

        $again = $this->finish($this->upgrade($installation));
        $this->assertSame(AppRunStatus::Succeeded, $again->status);
        $this->assertSame(AppInstallationStatus::Installed, $installation->refresh()->status);
        $this->assertNull($installation->error);
        $this->assertSame('1.4.0', $installation->version->version);
    }

    public function test_inputs_the_new_version_adds_are_asked_for_and_kept()
    {
        $installation = $this->installed();
        $inputs = [
            ['key' => 'port', 'type' => 'port', 'default' => 51820],
            ['key' => 'dns', 'type' => 'domain', 'label' => ['en' => 'DNS server']],
            ['key' => 'mtu', 'type' => 'integer', 'default' => 1420],
        ];
        $this->publishVersion('wireguard', '1.4.0', changes: ['inputs' => $inputs]);

        $offer = app(AppUpgrades::class)->offer($installation->refresh());
        $this->assertSame(['dns', 'mtu'], array_column($offer['inputs'] ?? [], 'key'));

        try {
            $this->upgrade($installation);
            $this->fail('The new input without a default is required.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inputs.dns', $e->errors());
        }

        try {
            $this->upgrade($installation, ['dns' => 'dns.example.com', 'port' => 22]);
            $this->fail('Installed inputs cannot be changed by an upgrade.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inputs.port', $e->errors());
        }

        $run = $this->upgrade($installation, ['dns' => 'dns.example.com']);
        $this->assertSame(['port' => 51000, 'dns' => 'dns.example.com', 'mtu' => 1420], $run->inputs);

        $this->finish($run);
        $this->assertSame(['port' => 51000, 'dns' => 'dns.example.com', 'mtu' => 1420], $installation->refresh()->inputs);
    }

    public function test_upgrades_need_an_offered_version_an_idle_app_and_an_active_server()
    {
        $installation = $this->installed();

        try {
            $this->upgrade($installation);
            $this->fail('Nothing to upgrade to.');
        } catch (AppNotEligible $e) {
            $this->assertSame('No upgrade is available for this app.', $e->getMessage());
        }

        $this->publishVersion('wireguard', '1.4.0');
        app(AppRunner::class)->action($installation, 'list_clients', [], $this->admin, null);

        try {
            $this->upgrade($installation);
            $this->fail('An action is still running.');
        } catch (AppInstallConflict) {
        }

        $this->assertSame(0, AppRun::query()->where('kind', AppRunKind::Upgrade)->count());
    }

    public function test_a_version_an_upgrade_moves_to_counts_as_in_use()
    {
        $installation = $this->installed();
        $target = $this->publishVersion('wireguard', '1.4.0');
        $this->assertFalse($target->inUse());

        $this->upgrade($installation);

        $this->assertTrue($target->inUse(), 'A package must not replace its content.');
        $this->assertFalse(AppRecipe::query()->where('slug', 'openvpn')->firstOrFail()->latestVersion?->inUse());
    }
}
