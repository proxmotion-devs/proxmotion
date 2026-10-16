<?php

namespace Proxmotion\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use Proxmotion\Whmcs\ApiClient;
use Proxmotion\Whmcs\Module;

/**
 * WHMCS module tests with the Proxmotion API faked (FakeTransport).
 */
abstract class ModuleTestCase extends TestCase
{
    const SERVER_ID = '9b2f2c1e-0000-4000-8000-000000000001';

    const PACKAGE_ID = '9b2f2c1e-0000-4000-8000-0000000000a1';

    /** @var FakeTransport */
    protected $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeTransport();
        $GLOBALS['whmcs_calls'] = ['logModuleCall' => [], 'localAPI' => []];
        unset($GLOBALS['CONFIG']);
    }

    /**
     * WHMCS module parameters of service 42.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function params(array $overrides = []): array
    {
        return array_replace([
            'serviceid' => 42,
            'userid' => 7,
            'domain' => 'web1.example.com',
            'password' => 'a-long-whmcs-password',
            'serverhostname' => 'panel.example.com',
            'serverip' => '',
            'serversecure' => 'on',
            'serverport' => '',
            'serverpassword' => 'pmx_secret-token-value',
            'serveraccesshash' => '',
            'configoption1' => self::PACKAGE_ID,
            'configoption2' => 'location-uuid',
            'configoption3' => 'os-uuid',
            'configoption4' => 'default',
            'configoptions' => [],
            'customfields' => [],
            'clientsdetails' => ['language' => 'english'],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function module(array $params = []): Module
    {
        $params = $this->params($params);

        return new Module($params, new ApiClient(Module::baseUrl($params) . '/api/v1', 'pmx_secret-token-value', $this->api), function () {
        });
    }

    /**
     * GET /servers?external_id=42 answering with this server (or none).
     *
     * @param array<string, mixed>|null $server
     */
    protected function serviceHas(?array $server): void
    {
        $this->api->on('GET /servers?external_id=42', 200, ['data' => $server === null ? [] : [$server]]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function server(array $overrides = []): array
    {
        return array_replace([
            'id' => self::SERVER_ID,
            'external_id' => '42',
            'hostname' => 'web1.example.com',
            'status' => 'active',
            'power_state' => 'running',
            'location' => ['id' => 'location-uuid', 'name' => 'Istanbul'],
            'os_template' => ['id' => 'os-uuid', 'name' => 'Ubuntu 24.04'],
            'resources' => ['cpu_cores' => 2, 'memory_mib' => 4096, 'disk_mib' => 40960, 'port_speed_mbps' => 1000, 'traffic_bytes' => null],
            'addresses' => [['address' => '203.0.113.10', 'version' => 4, 'prefix' => 24, 'gateway' => '203.0.113.1', 'ptr' => null]],
            'root_password_available' => false,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function task(string $type, string $status = 'queued', array $overrides = []): array
    {
        return ['data' => array_replace([
            'id' => 'task-uuid',
            'type' => $type,
            'status' => $status,
            'server' => ['id' => self::SERVER_ID, 'external_id' => '42', 'status' => 'active'],
            'steps' => [],
            'error' => null,
        ], $overrides)];
    }

    /**
     * @return list<array{command: string, values: array<string, mixed>}>
     */
    protected function localApiCalls(): array
    {
        return $GLOBALS['whmcs_calls']['localAPI'];
    }
}
