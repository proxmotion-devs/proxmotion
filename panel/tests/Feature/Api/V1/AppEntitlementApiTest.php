<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Apps\RecipeSynchronizer;
use App\Enums\ServerStatus;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\Server;
use Tests\Concerns\OffersApps;

/**
 * Granting and revoking rights to paid apps through the API (docs/adr/0019).
 */
class AppEntitlementApiTest extends ApiTestCase
{
    use OffersApps;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        app(RecipeSynchronizer::class)->sync();

        $this->offerApps();
        $this->server = $this->provisionedServer();

        // OpenVPN stands in for a paid app.
        $openvpn = AppRecipe::query()->with('latestVersion')->where('slug', 'openvpn')->firstOrFail();
        $version = $openvpn->latestVersion;
        $this->assertNotNull($version);
        $version->forceFill(['definition' => ['billing' => 'paid'] + $version->definition])->save();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/servers/{$this->server->uuid}/app-entitlements{$suffix}";
    }

    public function test_granting_answers_201_then_200_with_the_same_entitlement()
    {
        $first = $this->api('POST', $this->url(), ['app' => 'openvpn', 'reference' => 'addon-42'])
            ->assertCreated()
            ->assertJsonPath('data.app.id', 'openvpn')
            ->assertJsonPath('data.server.external_id', 'whmcs-1001')
            ->assertJsonPath('data.source', 'api')
            ->assertJsonPath('data.reference', 'addon-42')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.revoked_at', null);

        $this->api('POST', $this->url(), ['app' => 'openvpn', 'reference' => 'addon-43'])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.reference', 'addon-42');

        $this->assertSame($this->token->id, $this->server->appEntitlements()->sole()->api_token_id);
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => 'openvpn'])->assertAccepted();
    }

    public function test_free_and_unknown_apps_cannot_be_granted()
    {
        $this->api('POST', $this->url(), ['app' => 'wireguard'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['app' => 'This app is free and needs no entitlement.']);
        $this->api('POST', $this->url(), ['app' => 'nothing'])->assertUnprocessable()->assertJsonValidationErrors(['app']);
        $this->api('POST', $this->url(), ['app' => 'openvpn', 'reference' => str_repeat('x', 192)])->assertUnprocessable()->assertJsonValidationErrors(['reference']);

        $this->assertSame(0, $this->server->appEntitlements()->count());
    }

    public function test_terminated_servers_get_no_entitlements()
    {
        $this->server->forceFill(['status' => ServerStatus::Terminated])->save();

        $this->api('POST', $this->url(), ['app' => 'openvpn'])->assertConflict();
    }

    public function test_revoking_answers_200_then_404()
    {
        $this->api('POST', $this->url(), ['app' => 'openvpn'])->assertCreated();

        $this->api('DELETE', $this->url('/openvpn'))
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.revoked_at', fn ($value) => is_string($value));
        $this->api('DELETE', $this->url('/openvpn'))->assertNotFound();
        $this->api('DELETE', $this->url('/nothing'))->assertNotFound();

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => 'openvpn'])->assertUnprocessable();
        $this->assertSame(1, AuditLog::query()->where('action', 'app.entitlement_revoked')->count());
    }

    public function test_the_list_shows_active_and_revoked_entitlements()
    {
        $this->api('POST', $this->url(), ['app' => 'openvpn'])->assertCreated();
        $this->api('DELETE', $this->url('/openvpn'))->assertOk();
        $this->api('POST', $this->url(), ['app' => 'openvpn', 'reference' => 'second'])->assertCreated();

        $this->api('GET', $this->url())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.0.reference', 'second')
            ->assertJsonPath('data.1.active', false);
    }
}
