<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Apps\AppEntitlements;
use App\Domain\Apps\AppInstallations;
use App\Domain\Apps\InstallationPoller;
use App\Domain\Apps\RecipeSynchronizer;
use App\Domain\Apps\RunPoller;
use App\Enums\ApiScope;
use App\Enums\AppEntitlementSource;
use App\Enums\AppInstallationStatus;
use App\Jobs\DeliverWebhook;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AppRun;
use App\Models\BillingSystem;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\Server;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\OffersApps;
use Tests\Concerns\PublishesRecipeVersions;

/**
 * App Marketplace endpoints (docs/adr/0015): the catalog, installing,
 * reading installations with and without the apps:secrets scope.
 */
class AppApiTest extends ApiTestCase
{
    use OffersApps, PublishesRecipeVersions;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        app(RecipeSynchronizer::class)->sync();

        $this->offerApps();
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
        AppRecipe::query()->whereIn('slug', ['aapanel', 'chocolatey', 'coolify', 'cpanel', 'cyberpanel', 'docker', 'fastpanel', 'hestiacp', 'iis', 'lamp', 'lemp', 'nodejs', 'plesk', 'portainer', 'pterodactyl', 'sqlserver-express', 'whmcs', 'wordpress'])->update(['is_active' => false]);

        $response = $this->api('GET', '/api/v1/apps')->assertOk();

        $this->assertSame(['OpenVPN', 'WireGuard'], array_column($response->json('data'), 'name'));
        $response->assertJsonPath('data.0.id', 'openvpn')
            ->assertJsonPath('data.0.inputs.1', ['key' => 'protocol', 'type' => 'enum', 'label' => ['en' => 'Protocol', 'tr' => 'Protokol'], 'required' => false, 'default' => 'udp', 'options' => ['udp', 'tcp'], 'min' => null, 'max' => null, 'max_length' => null])
            ->assertJsonPath('data.0.outputs.0.filename', 'client.ovpn');
    }

    public function test_the_catalog_follows_the_versions_ticked_for_each_app()
    {
        $wireguard = AppRecipe::query()->where('slug', 'wireguard')->firstOrFail();
        $trixie = OsTemplate::factory()->create(['distribution' => 'debian', 'version' => '13']);
        $bookworm = OsTemplate::factory()->create(['distribution' => 'debian', 'version' => '12']);
        $wireguard->osVersions()->attach($bookworm->operating_system_version_id);
        $ids = fn (OsTemplate $template): array => array_column($this->api('GET', '/api/v1/apps?os_template='.$template->uuid)->json('data'), 'id');

        // Only what administrators ticked (docs/adr/0026), whatever the recipes say.
        $this->assertSame([], $ids($trixie));
        $this->assertSame(['wireguard'], $ids($bookworm));

        $response = $this->api('GET', '/api/v1/apps')->assertOk();
        $this->assertSame([['family' => 'ubuntu', 'versions' => ['24.04']], ['family' => 'debian', 'versions' => ['12']]], array_column($response->json('data'), 'os', 'id')['wireguard']);
        $apps = array_column($response->json('data'), 'requires', 'id');
        $this->assertSame(['platform' => 'linux', 'os' => ['debian'], 'os_families' => ['debian'], 'distributions' => [], 'min_versions' => ['ubuntu' => '22.04', 'debian' => '12'], 'max_versions' => []], $apps['wireguard']);
        $this->assertStringContainsString('"max_versions":{}', $response->getContent() ?: '', 'An empty map is an object.');
    }

    public function test_the_catalog_can_be_filtered_by_os_template_package_or_server()
    {
        $windows = OsTemplate::factory()->windows()->create();
        $small = Package::factory()->create(['memory_mib' => 768]);

        $this->assertSame([], $this->api('GET', '/api/v1/apps?os_template='.$windows->uuid)->assertOk()->json('data'));
        $this->assertSame(['nodejs', 'openvpn', 'wireguard'], array_column($this->api('GET', '/api/v1/apps?package='.$small->uuid)->json('data'), 'id'));
        $this->assertSame(['coolify', 'cyberpanel', 'docker', 'portainer', 'fastpanel', 'hestiacp', 'lamp', 'lemp', 'nodejs', 'openvpn', 'plesk', 'pterodactyl', 'whmcs', 'wireguard', 'wordpress', 'aapanel', 'cpanel'], array_column($this->api('GET', '/api/v1/apps?os_template='.$this->osTemplate->uuid.'&server='.$this->server->uuid)->json('data'), 'id'));
    }

    public function test_paid_apps_are_listed_and_say_whether_the_server_is_entitled()
    {
        $openvpn = AppRecipe::query()->with('latestVersion')->where('slug', 'openvpn')->firstOrFail();
        $version = $openvpn->latestVersion;
        $this->assertNotNull($version);
        $version->forceFill(['definition' => ['billing' => 'paid'] + $version->definition])->save();

        $catalog = $this->api('GET', '/api/v1/apps')->assertOk()->json('data');
        $this->assertSame('paid', collect($catalog)->firstWhere('id', 'openvpn')['billing']);
        $this->assertArrayNotHasKey('entitled', $catalog[0], 'Only with a server.');

        $entitled = fn (): array => array_column($this->api('GET', '/api/v1/apps?server='.$this->server->uuid)->assertOk()->json('data'), 'entitled', 'id');
        $this->assertFalse($entitled()['openvpn']);
        $this->assertTrue($entitled()['wireguard']);

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => 'openvpn'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This app has to be bought for the server first.');

        app(AppEntitlements::class)->grant($this->server, $openvpn->refresh(), AppEntitlementSource::Api, null);

        $this->assertTrue($entitled()['openvpn']);
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => 'openvpn'])->assertAccepted();
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
            ->assertJsonPath('data.app', ['id' => 'openvpn', 'name' => 'OpenVPN', 'version' => '1.3.1'])
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

    public function test_trials_of_drafts_are_never_shown_or_sent_to_billing_systems()
    {
        // Test servers have no billing system; the API and webhooks leave trials out anyway (docs/adr/0018 §7).
        WebhookEndpoint::factory()->for($this->billingSystem)->create();
        $recipe = AppRecipe::factory()->create();
        $trial = AppInstallation::factory()->for($this->server)->for($recipe, 'recipe')->create(['is_trial' => true, 'status' => AppInstallationStatus::Installing]);
        $uri = "/api/v1/servers/{$this->server->uuid}/apps";

        $this->api('GET', $uri)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', "{$uri}/{$trial->uuid}")->assertNotFound();
        $this->api('POST', "{$uri}/{$trial->uuid}/retry")->assertNotFound();
        $this->api('DELETE', "{$uri}/{$trial->uuid}")->assertNotFound();

        app(AppInstallations::class)->installed($trial->load('server'), []);

        $this->assertSame(0, WebhookDelivery::query()->count());
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

    public function test_installations_say_what_can_be_done_with_them()
    {
        $installation = $this->installed();

        $response = $this->api('GET', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}")->assertOk()
            ->assertJsonPath('data.can_uninstall', true)
            ->assertJsonPath('data.actions.0.key', 'add_client')
            ->assertJsonPath('data.actions.0.label', ['en' => 'Add a client', 'tr' => 'İstemci ekle'])
            ->assertJsonPath('data.actions.0.inputs.0.key', 'name');

        $this->assertSame(['add_client', 'revoke_client', 'list_clients'], array_column($response->json('data.actions'), 'key'));
        $catalog = collect($this->api('GET', '/api/v1/apps')->json('data'))->keyBy('id');
        $this->assertTrue($catalog['wireguard']['can_uninstall']);
        $this->assertSame('list_clients', $catalog['wireguard']['actions'][2]['key']);
    }

    public function test_uninstalling_answers_202_with_the_run()
    {
        $installation = $this->installed();
        [, $readOnly] = $this->issueToken([ApiScope::ServersRead]);
        $uri = "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}";

        $this->api('DELETE', $uri, secret: $readOnly)->assertForbidden();

        $run = $this->api('DELETE', $uri)
            ->assertAccepted()
            ->assertJsonPath('data.kind', 'uninstall')
            ->assertJsonPath('data.action', null)
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.installation_id', $installation->uuid)
            ->json('data.id');

        $this->api('GET', $uri)->assertJsonPath('data.status', 'uninstalling')->assertJsonPath('data.can_uninstall', false);
        $this->api('GET', "{$uri}/runs/{$run}")->assertOk()->assertJsonPath('data.status', 'running');
        $this->api('DELETE', $uri)->assertConflict();
    }

    public function test_an_action_answers_202_and_its_outputs_need_the_apps_secrets_scope()
    {
        Queue::fake([DeliverWebhook::class]);
        WebhookEndpoint::factory()->for($this->billingSystem)->create();
        $installation = $this->installed();
        $uri = "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}";

        $this->api('POST', "{$uri}/actions/add_client", ['inputs' => ['name' => '../etc']])->assertUnprocessable()->assertJsonValidationErrors('inputs.name');
        $this->api('POST', "{$uri}/actions/fly", ['inputs' => []])->assertUnprocessable()->assertJsonPath('message', 'This app has no action fly.');

        $id = $this->api('POST', "{$uri}/actions/add_client", ['inputs' => ['name' => 'phone']])
            ->assertAccepted()
            ->assertJsonPath('data.kind', 'action')
            ->assertJsonPath('data.action', 'add_client')
            ->assertJsonPath('data.inputs', ['name' => 'phone'])
            ->json('data.id');

        $run = AppRun::query()->where('uuid', $id)->sole();
        $this->proxmox->guestFiles[$this->server->vmid][$run->guestDirectory().'/outputs/client.conf'] = "[Interface]\nPrivateKey = phone-secret\n";
        $this->proxmox->guestFiles[$this->server->vmid][$run->guestDirectory().'/status.json'] = '{"status":"succeeded"}';
        app(RunPoller::class)->poll($run);

        [, $withoutSecrets] = $this->issueToken([ApiScope::ServersRead]);
        $this->api('GET', "{$uri}/runs/{$id}", secret: $withoutSecrets)->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonMissingPath('data.outputs');
        $this->api('GET', "{$uri}/runs/{$id}")->assertJsonPath('data.outputs.0.content_base64', base64_encode("[Interface]\nPrivateKey = phone-secret\n"));

        $this->api('GET', "{$uri}/runs")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.outputs.0.filename', 'client.conf');
        $this->api('GET', "{$uri}/runs", secret: $withoutSecrets)->assertOk()->assertJsonMissingPath('data.0.outputs');

        $delivery = WebhookDelivery::query()->where('event', 'app.action_succeeded')->sole();
        $this->assertSame('add_client', json_decode($delivery->payload, true)['data']['run']['action']);
        $this->assertStringNotContainsString('phone-secret', $delivery->payload);
    }

    public function test_upgrading_answers_202_with_the_run_and_sends_a_webhook()
    {
        Queue::fake([DeliverWebhook::class]);
        WebhookEndpoint::factory()->for($this->billingSystem)->create();
        $installation = $this->installed();
        $uri = "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}";

        $this->api('GET', $uri)->assertJsonPath('data.upgrade', null);
        $this->api('POST', "{$uri}/upgrade")->assertUnprocessable()->assertJsonPath('message', 'No upgrade is available for this app.');

        $inputs = [
            ['key' => 'port', 'type' => 'port', 'default' => 51820],
            ['key' => 'dns', 'type' => 'domain', 'label' => ['en' => 'DNS server']],
        ];
        $this->publishVersion('wireguard', '1.4.0', changes: ['inputs' => $inputs]);

        $this->api('GET', $uri)
            ->assertJsonPath('data.app.version', '1.3.1')
            ->assertJsonPath('data.upgrade.version', '1.4.0')
            ->assertJsonPath('data.upgrade.inputs.0.key', 'dns')
            ->assertJsonCount(1, 'data.upgrade.inputs');

        [, $readOnly] = $this->issueToken([ApiScope::ServersRead]);
        $this->api('POST', "{$uri}/upgrade", ['inputs' => ['dns' => 'dns.example.com']], secret: $readOnly)->assertForbidden();
        $this->api('POST', "{$uri}/upgrade", ['inputs' => []])->assertUnprocessable()->assertJsonValidationErrors('inputs.dns');

        $id = $this->api('POST', "{$uri}/upgrade", ['inputs' => ['dns' => 'dns.example.com']])
            ->assertAccepted()
            ->assertJsonPath('data.kind', 'upgrade')
            ->assertJsonPath('data.target_version', '1.4.0')
            ->assertJsonPath('data.inputs', ['port' => 51820, 'dns' => 'dns.example.com'])
            ->json('data.id');

        $this->api('GET', $uri)->assertJsonPath('data.status', 'upgrading')->assertJsonPath('data.upgrade', null);
        $this->api('POST', "{$uri}/upgrade", ['inputs' => ['dns' => 'dns.example.com']])->assertConflict();

        $run = AppRun::query()->where('uuid', $id)->sole();
        $this->proxmox->guestFiles[$this->server->vmid][$run->guestDirectory().'/status.json'] = '{"status":"succeeded"}';
        app(RunPoller::class)->poll($run);

        $this->api('GET', $uri)->assertJsonPath('data.status', 'installed')->assertJsonPath('data.app.version', '1.4.0')->assertJsonPath('data.upgrade', null);
        $this->api('GET', "{$uri}/runs/{$id}")->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.outputs', []);

        $delivery = WebhookDelivery::query()->where('event', 'app.upgraded')->sole();
        $payload = json_decode($delivery->payload, true);
        $this->assertSame('1.4.0', $payload['data']['installation']['app']['version']);
        $this->assertSame('upgrade', $payload['data']['run']['kind']);
        $this->assertStringNotContainsString('PrivateKey', $delivery->payload);
    }

    public function test_runs_and_installations_of_other_servers_are_not_found()
    {
        $installation = $this->installed();
        $other = $this->provisionedServer('whmcs-1002');
        $foreign = AppInstallation::factory()->for($other)->create();
        $foreignRun = AppRun::query()->forceCreate(['uuid' => (string) Str::uuid(), 'app_installation_id' => $foreign->id, 'kind' => 'uninstall', 'status' => 'failed', 'inputs' => []]);

        $this->api('DELETE', "/api/v1/servers/{$this->server->uuid}/apps/{$foreign->uuid}")->assertNotFound();
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$foreign->uuid}/upgrade")->assertNotFound();
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$foreign->uuid}/actions/add_client", ['inputs' => ['name' => 'x1']])->assertNotFound();
        $this->api('GET', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}/runs/{$foreignRun->uuid}")->assertNotFound();
        $this->assertSame(0, AppRun::query()->where('app_installation_id', $installation->id)->count());
    }

    public function test_cancelling_answers_202_with_the_failed_installation_and_sends_a_webhook()
    {
        Queue::fake([DeliverWebhook::class]);
        WebhookEndpoint::factory()->for($this->billingSystem)->create();
        $installation = AppInstallation::query()->where('uuid', $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps", ['app' => 'wireguard'])->json('data.id'))->sole();

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}/cancel")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Cancelled by the billing system.');

        $this->assertSame('failed', json_decode(WebhookDelivery::query()->where('event', 'app.failed')->sole()->payload, true)['data']['installation']['status']);
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}/cancel")
            ->assertConflict()
            ->assertExactJson(['message' => 'Only a running installation can be cancelled.']);
    }

    public function test_cancelling_a_run_answers_202_with_the_run()
    {
        $installation = $this->installed();
        $run = $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}/actions/list_clients")->assertAccepted()->json('data.id');

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}/runs/{$run}/cancel")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Cancelled by the billing system.');
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}/runs/{$run}/cancel")->assertConflict();
    }

    public function test_installations_and_runs_of_other_servers_cannot_be_cancelled()
    {
        $installation = $this->installed();
        $other = $this->provisionedServer('whmcs-1002');
        $foreign = AppInstallation::factory()->for($other)->create(['status' => AppInstallationStatus::Installing]);
        $foreignRun = AppRun::query()->forceCreate(['uuid' => (string) Str::uuid(), 'app_installation_id' => $foreign->id, 'kind' => 'action', 'action' => 'add_client', 'status' => 'running', 'inputs' => []]);

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$foreign->uuid}/cancel")->assertNotFound();
        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/apps/{$installation->uuid}/runs/{$foreignRun->uuid}/cancel")->assertNotFound();
        $this->assertSame(AppInstallationStatus::Installing, $foreign->refresh()->status);
        $this->assertSame('running', $foreignRun->refresh()->status->value);
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
