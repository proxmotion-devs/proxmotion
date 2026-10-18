<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\AppEntitlements;
use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppNotEligible;
use App\Domain\Apps\AppNotEntitled;
use App\Domain\Apps\InstallationPoller;
use App\Domain\Apps\RecipeSynchronizer;
use App\Domain\Servers\ServerReinstaller;
use App\Enums\AppEntitlementSource;
use App\Enums\AppInstallationStatus;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Paid apps need an entitlement on the server (docs/adr/0019): granted by
 * an order with the app, the billing system or an administrator; revoking
 * only stops new installs.
 */
class AppEntitlementTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private User $admin;

    private AppRecipe $paid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->admin = User::factory()->create();
        app(RecipeSynchronizer::class)->sync();

        // WireGuard stands in for a paid app.
        $this->paid = AppRecipe::query()->with('latestVersion')->where('slug', 'wireguard')->firstOrFail();
        $version = $this->paid->latestVersion;
        $this->assertNotNull($version);
        $version->forceFill(['definition' => ['billing' => 'paid'] + $version->definition])->save();
        $this->paid->refresh();
    }

    private function server(): Server
    {
        return app(ServerCreator::class)->create($this->order())->refresh();
    }

    private function entitlements(): AppEntitlements
    {
        return app(AppEntitlements::class);
    }

    public function test_granting_is_idempotent_and_audited()
    {
        $server = $this->server();

        [$first, $created] = $this->entitlements()->grant($server, $this->paid, AppEntitlementSource::Api, $this->admin, 'addon-17');
        [$again, $createdAgain] = $this->entitlements()->grant($server, $this->paid, AppEntitlementSource::Api, $this->admin, 'addon-18');

        $this->assertTrue($created);
        $this->assertFalse($createdAgain);
        $this->assertTrue($first->is($again));
        $this->assertSame('addon-17', $again->reference);
        $this->assertSame(1, AuditLog::query()->where('action', 'app.entitlement_granted')->count());

        $this->expectException(AppNotEligible::class);
        $this->expectExceptionMessage('This app is free and needs no entitlement.');
        $this->entitlements()->grant($server, AppRecipe::query()->where('slug', 'docker')->firstOrFail(), AppEntitlementSource::Api, $this->admin);
    }

    public function test_a_paid_app_installs_later_only_with_an_entitlement()
    {
        $server = $this->server();
        $installer = app(AppInstaller::class);

        try {
            $installer->install($server, $this->paid, [], $this->admin, null);
            $this->fail('A paid app was installed without an entitlement.');
        } catch (AppNotEntitled $e) {
            $this->assertSame('This app has to be bought for the server first.', $e->translatedMessage());
        }

        $this->entitlements()->grant($server, $this->paid, AppEntitlementSource::Admin, $this->admin);
        $installation = $installer->install($server, $this->paid, [], $this->admin, null);

        $this->assertSame(AppInstallationStatus::Installing, $installation->refresh()->status);
    }

    public function test_revoking_keeps_the_installed_app_but_stops_retries()
    {
        $server = $this->server();
        $this->entitlements()->grant($server, $this->paid, AppEntitlementSource::Api, null);
        $installation = app(AppInstaller::class)->install($server, $this->paid, [], $this->admin, null);
        $this->proxmox->guestFiles[$server->vmid][$installation->guestDirectory().'/status.json'] = '{"status":"failed","step":"Installing","message":"apt failed"}';
        app(InstallationPoller::class)->poll($installation->refresh());

        $revoked = $this->entitlements()->revoke($server, $this->paid, $this->admin);

        $this->assertNotNull($revoked?->revoked_at);
        $this->assertNull($this->entitlements()->revoke($server, $this->paid, $this->admin), 'Nothing left to revoke.');
        $this->assertSame(AppInstallationStatus::Failed, $installation->refresh()->status, 'Revoking never touches installations.');

        $this->expectException(AppNotEntitled::class);
        app(AppInstaller::class)->retry($installation, $this->admin, null);
    }

    public function test_ordering_a_server_with_a_paid_app_grants_it()
    {
        $server = app(ServerCreator::class)->create($this->order(['app' => $this->paid]), $this->admin)->refresh();

        $entitlement = $server->appEntitlements()->sole();
        $this->assertSame(AppEntitlementSource::Order, $entitlement->source);
        $this->assertSame($this->admin->id, $entitlement->user_id);
        $this->assertSame(AppInstallationStatus::Installing, $server->appInstallations()->sole()->status);

        // The right from the order stays for reinstalls.
        app(ServerReinstaller::class)->request($server, $server->osTemplate, [], $this->admin, null, $this->paid);

        $this->assertSame(1, $server->appEntitlements()->count());
        $this->assertSame(AppInstallationStatus::Installing, $server->appInstallations()->latest('id')->firstOrFail()->status);
    }

    public function test_reinstalling_with_a_paid_app_needs_an_entitlement()
    {
        $server = $this->server();

        $this->expectException(AppNotEntitled::class);

        try {
            app(ServerReinstaller::class)->request($server, $server->osTemplate, [], $this->admin, null, $this->paid);
        } finally {
            $this->assertSame(0, $server->appEntitlements()->count());
            $this->assertSame(1, $server->tasks()->count(), 'Only the create task.');
        }
    }
}
