<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Apps\InstallationPoller;
use App\Domain\Apps\RecipeSynchronizer;
use App\Enums\ApiScope;
use App\Enums\AppInstallationStatus;
use App\Jobs\DeliverWebhook;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\BillingSystem;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\Server;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;

/**
 * App Marketplace endpoints (docs/adr/0015): the catalog, installing,
 * reading installations with and without the apps:secrets scope.
 */
class AppApiTest extends ApiTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        app(RecipeSynchronizer::class)->sync();
        $this->server = $this->provisionedServer();
    }

    private function installed(string $slug = 'wireguard'): AppInstallation
    {
        $installation = AppInstallation::query()->findOrFail(
            AppInstallation::query()->where('uuid', $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => $slug])->assertAccepted()->json('data.id'))->value('id'),
        );
        $directory = $installation->guestDirectory();
        $files = &$this->proxmox->guestFiles[$this->server->vmid];
        $files[$directory.'/status.json'] = '{"status":"installed","progress":100,"step":"Installed"}';
        $files[$directory.'/outputs.json'] = '{"endpoint":"203.0.113.2:51820"}';
        $files[$directory.'/outputs/client.conf'] = "[Interface]\nPrivateKey = secret\n";
        app(InstallationPoller::class)->poll($installation);

        return $installation->refresh();
    }

    public function test_the_catalog_lists_active_apps_with_their_inputs()
    {
        AppRecipe::query()->where('slug', 'docker')->update(['is_active' => false]);

        $response = $this->api('GET', '/api/v1/apps')->assertOk();

        $this->assertSame(['OpenVPN', 'WireGuard'], array_column($response->json('data'), 'name'));
        $response->assertJsonPath('data.0.id', 'openvpn')
            ->assertJsonPath('data.0.inputs.1', ['key' => 'protocol', 'type' => 'enum', 'label' => ['en' => 'Protocol', 'tr' => 'Protokol'], 'required' => false, 'default' => 'udp', 'options' => ['udp', 'tcp'], 'min' => null, 'max' => null, 'max_length' => null])
            ->assertJsonPath('data.0.outputs.0.filename', 'client.ovpn');
    }

    public function test_the_catalog_can_be_filtered_by_os_template_package_or_server()
    {
        $windows = OsTemplate::factory()->windows()->create();
        $small = Package::factory()->create(['memory_mib' => 768]);

        $this->assertSame([], $this->api('GET', '/api/v1/apps?os_template='.$windows->uuid)->assertOk()->json('data'));
        $this->assertSame(['openvpn', 'wireguard'], array_column($this->api('GET', '/api/v1/apps?package='.$small->uuid)->json('data'), 'id'));
        $this->assertSame(['docker', 'openvpn', 'wireguard'], array_column($this->api('GET', '/api/v1/apps?os_template='.$this->osTemplate->uuid.'&server='.$this->server->uuid)->json('data'), 'id'));
    }

    public function test_the_catalog_refuses_servers_of_another_billing_system()
    {
        $foreign = $this->provisionedServer('2001', BillingSystem::factory()->create());

        $this->api('GET', '/api/v1/apps?server='.$foreign->uuid)->assertUnprocessable()->assertJsonValidationErrors('server');
    }

    public function test_installing_answers_202_with_the_installation()
    {
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => 'openvpn', 'inputs' => ['port' => 443, 'protocol' => 'tcp']])
            ->assertAccepted()
            ->assertJsonPath('data.app', ['id' => 'openvpn', 'name' => 'OpenVPN', 'version' => '1.1.0'])
            ->assertJsonPath('data.server.external_id', 'whmcs-1001')
            ->assertJsonPath('data.status', 'installing')
            ->assertJsonPath('data.inputs', ['port' => 443, 'protocol' => 'tcp'])
            ->assertJsonMissingPath('data.outputs');

        $this->assertSame($this->token->id, AppInstallation::query()->sole()->api_token_id);
    }

    public function test_invalid_inputs_and_unfit_apps_answer_422()
    {
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => 'openvpn', 'inputs' => ['protocol' => 'icmp']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('inputs.protocol');

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => 'nothing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('app');

        $this->server->forceFill(['disk_mib' => 8192])->save();
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => 'docker'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'This app needs at least 10240 MiB of disk space.']);
    }

    public function test_a_second_install_while_one_runs_answers_409()
    {
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => 'wireguard'])->assertAccepted();

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => 'docker'])
            ->assertConflict()
            ->assertExactJson(['message' => 'Another app is still being installed on this server.']);
    }

    public function test_outputs_need_the_apps_secrets_scope()
    {
        $installation = $this->installed();
        $uri = "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}";
        [, $withoutSecrets] = $this->issueToken([ApiScope::ServersRead]);

        $this->api('GET', $uri, secret: $withoutSecrets)->assertOk()->assertJsonPath('data.status', 'installed')->assertJsonMissingPath('data.outputs');

        $this->api('GET', $uri)->assertOk()->assertJsonPath('data.outputs', [
            ['key' => 'client_config', 'type' => 'file', 'label' => ['en' => 'Client configuration', 'tr' => 'İstemci yapılandırması'], 'secret' => true, 'value' => null, 'filename' => 'client.conf', 'content_base64' => base64_encode("[Interface]\nPrivateKey = secret\n")],
            ['key' => 'endpoint', 'type' => 'text', 'label' => ['en' => 'Server endpoint', 'tr' => 'Sunucu adresi'], 'secret' => false, 'value' => '203.0.113.2:51820', 'filename' => null, 'content_base64' => null],
        ]);

        $this->api('GET', "/api/v1/servers/{$this->server->uuid}/apps")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.outputs.1.value', '203.0.113.2:51820');
    }

    public function test_password_inputs_are_never_shown()
    {
        $recipe = AppRecipe::factory()->create();
        $installation = AppInstallation::factory()->for($this->server)->for($recipe, 'recipe')->create(['inputs' => ['port' => 8443, 'admin_password' => 'a-long-secret-password']]);

        $this->api('GET', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}")
            ->assertOk()
            ->assertJsonPath('data.inputs', ['port' => 8443]);
    }

    public function test_an_installation_of_another_server_is_not_found()
    {
        $other = $this->provisionedServer('whmcs-1002');
        $installation = AppInstallation::factory()->for($other)->create();

        $this->api('GET', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}")->assertNotFound();
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}/retry")->assertNotFound();
    }

    public function test_only_failed_installations_can_be_retried()
    {
        $installation = $this->installed();

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}/retry")
            ->assertConflict()
            ->assertExactJson(['message' => 'Only a failed installation can be retried.']);

        $installation->forceFill(['status' => AppInstallationStatus::Failed, 'error' => 'boom'])->save();

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}/retry")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'installing')
            ->assertJsonPath('data.error', null);
    }

    public function test_a_finished_installation_is_sent_as_a_webhook_without_outputs()
    {
        Queue::fake([DeliverWebhook::class]);
        WebhookEndpoint::factory()->for($this->billingSystem)->create();

        $this->installed();

        $delivery = WebhookDelivery::query()->where('event', 'app.installed')->sole();
        $payload = json_decode($delivery->payload, true);
        $this->assertSame('installed', $payload['data']['installation']['status']);
        $this->assertSame('whmcs-1001', $payload['data']['server']['external_id']);
        $this->assertArrayNotHasKey('outputs', $payload['data']['installation']);
        $this->assertStringNotContainsString('PrivateKey', $delivery->payload);
    }
}
