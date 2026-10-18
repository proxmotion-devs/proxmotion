<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Apps\RecipeSynchronizer;
use App\Models\AppRecipe;
use App\Models\Server;
use Tests\Unit\PublicKeyTest;

/**
 * Apps chosen with a new server or a reinstall through the API
 * (docs/adr/0016 §5): app and app_inputs on POST /servers and
 * POST /servers/{id}/reinstall, and the catalog's mode=order.
 */
class OrderedAppApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(RecipeSynchronizer::class)->sync();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'external_id' => 'whmcs-3001',
            'package' => $this->package->uuid,
            'location' => $this->location->uuid,
            'os_template' => $this->osTemplate->uuid,
            'hostname' => 'vpn1.example.com',
            'ssh_keys' => [PublicKeyTest::ED25519],
            ...$overrides,
        ];
    }

    public function test_a_server_ordered_with_an_app_installs_it_after_the_first_boot()
    {
        $this->api('POST', '/api/v1/servers', $this->payload(['app' => 'wireguard', 'app_inputs' => ['port' => 51900]]))
            ->assertAccepted()
            ->assertJsonPath('data.status', 'succeeded');

        $server = Server::query()->sole();

        $this->api('GET', "/api/v1/servers/{$server->uuid}/apps")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.app.id', 'wireguard')
            ->assertJsonPath('data.0.mode', 'order')
            ->assertJsonPath('data.0.status', 'installing')
            ->assertJsonPath('data.0.inputs', ['port' => 51900]);
    }

    public function test_unfit_apps_and_invalid_inputs_answer_422_before_anything_is_created()
    {
        AppRecipe::query()->where('slug', 'docker')->update(['is_active' => false]);

        $this->api('POST', '/api/v1/servers', $this->payload(['app' => 'docker']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['app' => 'This app is not available.']);

        $this->api('POST', '/api/v1/servers', $this->payload(['app' => 'openvpn', 'app_inputs' => ['port' => 70000, 'command' => 'id']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['app_inputs.port', 'app_inputs.command']);

        $this->api('POST', '/api/v1/servers', $this->payload(['app' => 'no-such-app']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['app']);

        $this->assertSame(0, Server::query()->count());
    }

    public function test_a_reinstall_can_bring_an_app()
    {
        $server = $this->provisionedServer();

        $this->api('POST', "/api/v1/servers/{$server->uuid}/reinstall", [
            'os_template' => $this->osTemplate->uuid,
            'app' => 'openvpn',
            'app_inputs' => ['protocol' => 'tcp'],
        ])->assertAccepted()->assertJsonPath('data.type', 'reinstall');

        $this->api('GET', "/api/v1/servers/{$server->uuid}/apps")
            ->assertJsonPath('data.0.app.id', 'openvpn')
            ->assertJsonPath('data.0.mode', 'order')
            ->assertJsonPath('data.0.inputs', ['port' => 1194, 'protocol' => 'tcp']);

        $this->api('POST', "/api/v1/servers/{$server->uuid}/reinstall", [
            'os_template' => $this->osTemplate->uuid,
            'app' => 'openvpn',
            'app_inputs' => ['protocol' => 'sctp'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['app_inputs.protocol']);
    }

    public function test_the_catalog_lists_the_apps_for_new_systems_with_mode_order()
    {
        $wireguard = AppRecipe::query()->where('slug', 'wireguard')->firstOrFail();
        $version = $wireguard->latestVersion;
        $this->assertNotNull($version);
        $definition = $version->definition;
        $definition['install_modes'] = ['post'];
        $version->forceFill(['definition' => $definition])->save();

        $ids = fn (string $query): array => array_column($this->api('GET', '/api/v1/apps?os_template='.$this->osTemplate->uuid.$query)->assertOk()->json('data'), 'id');

        $this->assertContains('wireguard', $ids(''));
        $this->assertContains('wireguard', $ids('&mode=post'));
        $this->assertNotContains('wireguard', $ids('&mode=order'));
        $this->assertContains('openvpn', $ids('&mode=order'));

        $this->api('GET', '/api/v1/apps?mode=later')->assertUnprocessable()->assertJsonValidationErrors(['mode']);
        $this->api('POST', '/api/v1/servers', $this->payload(['app' => 'wireguard']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['app' => 'This app can only be installed on a running server.']);
    }
}
