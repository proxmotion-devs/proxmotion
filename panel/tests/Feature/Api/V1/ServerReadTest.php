<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ServerStatus;
use App\Models\Server;

class ServerReadTest extends ApiTestCase
{
    public function test_a_server_is_shown_with_its_addresses_and_live_power_state()
    {
        $server = $this->provisionedServer();
        $address = $server->ipAddresses()->firstOrFail();

        $this->api('GET', "/api/v1/servers/{$server->uuid}")
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $server->uuid,
                'external_id' => 'whmcs-1001',
                'external_client_id' => 'client-7',
                'hostname' => 'web1.example.com',
                'status' => 'active',
                'suspension_reason' => null,
                'power_state' => 'running',
                'network_model' => 'bridged',
                'package' => ['id' => $this->package->uuid, 'name' => $this->package->name],
                'location' => ['id' => $this->location->uuid, 'name' => $this->location->name],
                'os_template' => ['id' => $this->osTemplate->uuid, 'name' => $this->osTemplate->name],
                'resources' => [
                    'cpu_cores' => 2,
                    'memory_mib' => 4096,
                    'disk_mib' => 40960,
                    'port_speed_mbps' => 1000,
                    'traffic_bytes' => $server->traffic_bytes,
                ],
                'addresses' => [[
                    'address' => $address->address,
                    'version' => 4,
                    'prefix' => 29,
                    'gateway' => '203.0.113.1',
                    'ptr' => null,
                ]],
                'root_password_available' => true,
                'created_at' => $server->created_at?->toIso8601String(),
                'provisioned_at' => $server->provisioned_at?->toIso8601String(),
                'terminated_at' => null,
            ]]);
    }

    public function test_the_root_password_is_never_part_of_a_server()
    {
        $server = $this->provisionedServer();

        $this->api('GET', "/api/v1/servers/{$server->uuid}")->assertDontSee('a-very-long-password');
        $this->api('GET', '/api/v1/servers')->assertDontSee('a-very-long-password');
    }

    public function test_the_power_state_is_null_when_proxmox_cannot_be_asked_or_the_server_is_not_active()
    {
        $server = $this->provisionedServer();
        $server->forceFill(['status' => ServerStatus::Suspended])->save();

        $this->api('GET', "/api/v1/servers/{$server->uuid}")->assertOk()->assertJsonPath('data.power_state', null);
    }

    public function test_routed_ipv4_addresses_are_listed_as_slash_32()
    {
        $server = $this->provisionedServer();
        $server->forceFill(['network_model' => 'routed'])->save();

        $this->api('GET', "/api/v1/servers/{$server->uuid}")->assertJsonPath('data.addresses.0.prefix', 32);
    }

    public function test_servers_can_be_found_by_their_billing_ids()
    {
        $first = $this->provisionedServer('whmcs-1');
        $this->provisionedServer('whmcs-2');

        $this->api('GET', '/api/v1/servers?external_id=whmcs-1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->uuid)
            ->assertJsonMissingPath('data.0.power_state')
            ->assertJsonPath('meta.total', 1);

        $this->api('GET', '/api/v1/servers?external_client_id=client-7')->assertJsonCount(2, 'data');
        $this->api('GET', '/api/v1/servers?external_id=unknown')->assertJsonCount(0, 'data');
        $this->api('GET', '/api/v1/servers?status=suspended')->assertJsonCount(0, 'data');
        $this->api('GET', '/api/v1/servers?status=sleeping')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_unknown_servers_and_tasks_are_not_found()
    {
        $this->api('GET', '/api/v1/servers/00000000-0000-0000-0000-000000000000')->assertNotFound();
        $this->api('GET', '/api/v1/tasks/00000000-0000-0000-0000-000000000000')->assertNotFound();
        // The internal id is never accepted.
        $server = $this->provisionedServer();
        $this->api('GET', "/api/v1/servers/{$server->id}")->assertNotFound();
    }

    public function test_tasks_are_shown()
    {
        $server = $this->provisionedServer();
        $task = $server->tasks()->sole();

        $this->api('GET', "/api/v1/tasks/{$task->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $task->uuid)
            ->assertJsonPath('data.type', 'create')
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.server.id', $server->uuid)
            ->assertJsonPath('data.server.external_id', 'whmcs-1001');
    }

    public function test_terminated_servers_can_still_be_looked_up()
    {
        $server = $this->provisionedServer();
        $server->forceFill(['status' => ServerStatus::Terminated, 'terminated_at' => now()])->save();

        $this->api('GET', "/api/v1/servers/{$server->uuid}")->assertOk()->assertJsonPath('data.status', 'terminated');
        $this->assertNotNull(Server::query()->find($server->id));
    }
}
