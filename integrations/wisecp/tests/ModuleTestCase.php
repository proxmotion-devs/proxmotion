<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

use PHPUnit\Framework\TestCase;

/**
 * WISECP module tests with the Proxmotion API faked (FakeTransport).
 */
abstract class ModuleTestCase extends TestCase
{
    public const SERVER_ID = '9b2f2c1e-0000-4000-8000-000000000001';

    public const PACKAGE_ID = '9b2f2c1e-0000-4000-8000-0000000000a1';

    public const TOKEN = 'pmx_secret-token-value';

    protected FakeTransport $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = TestProxmotion::$api = new FakeTransport;
    }

    /**
     * A WISECP server of this module as WISECP passes it (fields decrypted).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function wisecpServer(array $overrides = []): array
    {
        return array_replace([
            'id' => 3,
            'name' => 'Proxmotion',
            'ip' => 'panel.example.com',
            'secure' => 1,
            'port' => 443,
            'password' => self::TOKEN,
            'fields' => ['webhook_secret' => ''],
        ], $overrides);
    }

    /**
     * The module for service 42 of customer 7.
     *
     * @param array<string, mixed> $options the service's options (creation_info: product settings)
     * @param array<string, mixed> $requirements order answers
     * @param array<string, mixed> $addons add-on values
     */
    protected function module(array $options = [], array $requirements = [], array $addons = []): TestProxmotion
    {
        $module = new TestProxmotion($this->wisecpServer());
        $module->service = ['id' => 42, 'owner_id' => 7, 'status' => 'active', 'suspended_reason' => ''];
        $module->options = array_replace([
            'server_id' => 3,
            'hostname' => 'web1.example.com',
            'creation_info' => [
                'package' => self::PACKAGE_ID,
                'location' => 'location-uuid',
                'os_template' => 'os-uuid',
                'network_model' => 'default',
            ],
        ], $options);
        $module->requirement_params = $requirements;
        $module->addon_params = $addons;

        return $module;
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
            'addresses' => [
                ['address' => '203.0.113.10', 'version' => 4, 'prefix' => 24, 'gateway' => '203.0.113.1', 'ptr' => 'web1.example.com'],
                ['address' => '2001:db8::10', 'version' => 6, 'prefix' => 64, 'gateway' => '2001:db8::1', 'ptr' => null],
            ],
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
}
