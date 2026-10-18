<?php

namespace Tests\Feature\Admin;

use App\Domain\Apps\RecipeSynchronizer;
use App\Enums\AppInstallationStatus;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\Server;
use App\Provisioning\ServerCreator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Concerns\PublishesRecipeVersions;
use Tests\Support\FakeProxmoxCluster;

/**
 * The admin side of the App Marketplace (docs/adr/0015).
 */
class AppManagementTest extends AdminTestCase
{
    use BuildsInfrastructure, PublishesRecipeVersions;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        app(RecipeSynchronizer::class)->sync();
    }

    public function test_the_apps_page_lists_the_synced_recipes()
    {
        $this->get(route('admin.apps.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/apps/Index')
                ->has('apps', 8)
                ->where('apps.0.slug', 'docker')
                ->where('apps.0.is_active', true)
                ->where('apps.0.os', ['linux: debian', 'ubuntu ≥ 22.04', 'debian ≥ 12']));
    }

    public function test_an_admin_can_deactivate_an_app()
    {
        $this->patch(route('admin.apps.update', 'docker'), ['is_active' => false])->assertRedirect();

        $this->assertFalse(AppRecipe::query()->where('slug', 'docker')->value('is_active'));
        $this->assertSame(1, AuditLog::query()->where('action', 'app.deactivated')->count());
    }

    public function test_the_server_page_shows_installations_and_installable_apps()
    {
        $this->post(route('admin.servers.apps', $this->server), ['app' => 'wireguard', 'inputs' => ['port' => 51821]])->assertRedirect();

        $installation = AppInstallation::query()->sole();
        $this->assertSame(AppInstallationStatus::Installing, $installation->status);
        $this->assertSame(['port' => 51821], $installation->inputs);
        $this->assertSame($this->admin->id, $installation->user_id);

        $this->get(route('admin.servers.show', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('apps', 1)
                ->where('apps.0.app', 'WireGuard')
                ->where('apps.0.status', 'installing')
                ->where('appOptions.0.slug', 'docker')
                ->where('appOptions.2.inputs.0.key', 'port'));
    }

    public function test_invalid_inputs_show_up_on_the_form()
    {
        $this->post(route('admin.servers.apps', $this->server), ['app' => 'openvpn', 'inputs' => ['protocol' => 'icmp']])
            ->assertSessionHasErrors('inputs.protocol');

        $this->assertSame(0, AppInstallation::query()->count());
    }

    public function test_admins_run_actions_and_uninstall_from_the_server_page()
    {
        $installation = AppInstallation::factory()->for($this->server)->for(AppRecipe::query()->where('slug', 'wireguard')->sole(), 'recipe')->create(['inputs' => ['port' => 51820]]);

        $this->post(route('admin.app-installations.action', ['appInstallation' => $installation, 'action' => 'add_client']), ['inputs' => ['name' => 'Bad Name']])
            ->assertSessionHasErrors('inputs.name');
        $this->post(route('admin.app-installations.action', ['appInstallation' => $installation, 'action' => 'add_client']), ['inputs' => ['name' => 'laptop']])
            ->assertRedirect();

        $this->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('apps.0.can_uninstall', true)
                ->where('apps.0.actions.0.key', 'add_client')
                ->where('apps.0.runs.0.kind', 'action')
                ->where('apps.0.runs.0.action', ['en' => 'Add a client', 'tr' => 'İstemci ekle'])
                ->where('apps.0.runs.0.status', 'running'));

        // One run at a time: the action is still running.
        $this->post(route('admin.app-installations.uninstall', $installation))->assertRedirect();
        $this->assertSame(AppInstallationStatus::Installed, $installation->refresh()->status);

        $installation->runs()->update(['status' => 'succeeded']);
        $this->post(route('admin.app-installations.uninstall', $installation))->assertRedirect();

        $this->assertSame(AppInstallationStatus::Uninstalling, $installation->refresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'app.uninstall_requested')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'app.action_requested')->count());
    }

    public function test_admins_upgrade_an_app_from_the_server_page()
    {
        $installation = AppInstallation::factory()->for($this->server)->for(AppRecipe::query()->where('slug', 'wireguard')->sole(), 'recipe')->create(['inputs' => ['port' => 51820]]);
        $this->publishVersion('wireguard', '1.4.0', changes: ['inputs' => [
            ['key' => 'port', 'type' => 'port', 'default' => 51820],
            ['key' => 'dns', 'type' => 'domain'],
        ]]);

        $this->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('apps.0.upgrade.version', '1.4.0')
                ->where('apps.0.upgrade.inputs.0.key', 'dns'));

        $this->post(route('admin.app-installations.upgrade', $installation), ['inputs' => []])->assertSessionHasErrors('inputs.dns');
        $this->post(route('admin.app-installations.upgrade', $installation), ['inputs' => ['dns' => 'dns.example.com']])->assertRedirect();

        $this->assertSame(AppInstallationStatus::Upgrading, $installation->refresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'app.upgrade_requested')->count());

        $this->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('apps.0.status', 'upgrading')
                ->where('apps.0.upgrade', null)
                ->where('apps.0.runs.0.kind', 'upgrade')
                ->where('apps.0.runs.0.target_version', '1.4.0'));
    }

    public function test_a_failed_installation_can_be_retried()
    {
        $installation = AppInstallation::factory()->for($this->server)->for(AppRecipe::query()->where('slug', 'docker')->sole(), 'recipe')->create([
            'status' => AppInstallationStatus::Failed,
            'inputs' => [],
            'error' => 'boom',
        ]);

        $this->post(route('admin.app-installations.retry', $installation))->assertRedirect();

        $this->assertSame(AppInstallationStatus::Installing, $installation->refresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'app.install_retried')->count());
    }
}
