<?php

namespace Proxmotion\Whmcs\Tests;

require_once __DIR__ . '/bootstrap.php';

use Proxmotion\Whmcs\AddonHooks;
use Proxmotion\Whmcs\ApiClient;
use Proxmotion\Whmcs\Module;

/**
 * Paid apps sold as product addons (docs/adr/0019): module setting 6 maps
 * addon ids to apps; activation grants, cancellation revokes.
 */
class AddonHooksTest extends ModuleTestCase
{
    /** @var FakeServiceStore */
    private $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new FakeServiceStore();
        $this->store->params = $this->params(['configoption6' => "12=cpanel\r\n 13 = plesk ,bad line,14=Not_An_App"]);
    }

    private function hooks(): AddonHooks
    {
        return new AddonHooks($this->store, function (array $params) {
            return new Module($params, new ApiClient(Module::baseUrl($params) . '/api/v1', 'pmx_secret-token-value', $this->api), function () {
            });
        });
    }

    public function test_activating_a_mapped_addon_grants_its_app()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/app-entitlements', 201, ['data' => ['id' => 'entitlement-uuid', 'active' => true]]);

        $this->hooks()->activated(['id' => 501, 'userid' => 7, 'serviceid' => 42, 'addonid' => 13]);

        $this->assertSame(['app' => 'plesk', 'reference' => 'whmcs-addon-501'], $this->api->last('POST /servers/' . self::SERVER_ID . '/app-entitlements')['body']);
        $this->assertSame([['message' => 'Proxmotion: granted the paid app plesk for service #42 (addon #13)', 'userid' => 7]], $this->store->logged);
    }

    public function test_other_addons_and_other_services_are_ignored()
    {
        $this->hooks()->activated(['id' => 502, 'userid' => 7, 'serviceid' => 42, 'addonid' => 14]);
        $this->hooks()->activated(['id' => 503, 'userid' => 7, 'serviceid' => 42, 'addonid' => 99]);
        $this->store->params = null;
        $this->hooks()->activated(['id' => 504, 'userid' => 7, 'serviceid' => 43, 'addonid' => 12]);

        $this->assertSame([], $this->api->sent());
        $this->assertSame([], $this->store->logged);
    }

    public function test_cancelling_revokes_and_a_missing_right_is_fine()
    {
        $this->serviceHas($this->server());
        $this->api->on('DELETE /servers/' . self::SERVER_ID . '/app-entitlements/cpanel', 200, ['data' => ['active' => false]]);
        $this->api->on('DELETE /servers/' . self::SERVER_ID . '/app-entitlements/plesk', 404, ['message' => 'The server has no active entitlement to this app.']);

        $this->hooks()->ended(['id' => 501, 'userid' => 7, 'serviceid' => 42, 'addonid' => 12]);
        $this->hooks()->ended(['id' => 502, 'userid' => 7, 'serviceid' => 42, 'addonid' => 13]);

        $this->assertSame([
            'Proxmotion: revoked the paid app cpanel for service #42 (addon #12)',
            'Proxmotion: revoked the paid app plesk for service #42 (addon #13)',
        ], array_column($this->store->logged, 'message'));
    }

    public function test_a_terminated_server_has_nothing_to_revoke()
    {
        $this->serviceHas(null);

        $this->hooks()->ended(['id' => 501, 'userid' => 7, 'serviceid' => 42, 'addonid' => 12]);

        $this->assertSame(['GET /servers?external_id=42'], $this->api->sent());
    }

    public function test_failures_are_logged_and_never_stop_whmcs()
    {
        $this->serviceHas(null);

        $this->hooks()->activated(['id' => 501, 'userid' => 7, 'serviceid' => 42, 'addonid' => 12]);

        $this->assertCount(1, $this->store->logged);
        $this->assertStringStartsWith('Proxmotion: could not grant the paid app of addon #12 for service #42: ', $this->store->logged[0]['message']);
    }
}
