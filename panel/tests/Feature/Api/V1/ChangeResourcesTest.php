<?php

namespace Tests\Feature\Api\V1;

use App\Enums\NetworkModel;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Package;
use App\Models\Server;
use App\Models\Task;

/**
 * POST /api/v1/servers/{id}/package: upgrades and downgrades.
 */
class ChangeResourcesTest extends ApiTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->provisionedServer();
    }

    private function url(): string
    {
        return "/api/v1/servers/{$this->server->uuid}/package";
    }

    public function test_a_server_moves_to_a_bigger_package()
    {
        $bigger = Package::factory()->create([
            'cpu_cores' => 4, 'memory_mib' => 8192, 'disk_mib' => 81920, 'ipv4_count' => 2, 'ipv6_count' => 0,
            'port_speed_mbps' => 2000, 'network_models' => [NetworkModel::Bridged],
        ]);

        $this->api('POST', $this->url(), ['package' => $bigger->uuid])
            ->assertAccepted()
            ->assertJsonPath('data.type', 'change_resources')
            ->assertJsonPath('data.status', 'succeeded');

        $server = $this->server->refresh();
        $this->assertSame($bigger->id, $server->package_id);
        $this->assertSame([4, 8192, 81920, 2000], [$server->cpu_cores, $server->memory_mib, $server->disk_mib, $server->port_speed_mbps]);
        $this->assertSame(2, $server->ipAddresses()->count());
        $this->assertSame($this->token->id, Task::query()->where('type', TaskType::ChangeResources)->sole()->api_token_id);

        $this->api('GET', "/api/v1/servers/{$server->uuid}")
            ->assertJsonPath('data.reboot_required', true)
            ->assertJsonPath('data.package.id', $bigger->uuid);

        // A restart through the API applies the waiting CPU and memory.
        $this->api('POST', "/api/v1/servers/{$server->uuid}/power", ['action' => 'reboot'])->assertAccepted();
        $this->api('GET', "/api/v1/servers/{$server->uuid}")->assertJsonPath('data.reboot_required', false);
    }

    public function test_limits_come_from_the_package_and_stay_without_one()
    {
        // docs/adr/0034: the API takes no overrides for them.
        $limited = Package::factory()->create([
            'cpu_cores' => 2, 'memory_mib' => 4096, 'disk_mib' => 40960, 'ipv4_count' => 1, 'ipv6_count' => 0,
            'cpu_limit' => 1.5, 'disk_iops' => 1000, 'network_models' => [NetworkModel::Bridged],
        ]);

        $this->api('POST', $this->url(), ['package' => $limited->uuid])->assertAccepted();
        $this->assertSame(['1.50', 1000], [$this->server->refresh()->cpu_limit, $this->server->disk_iops]);

        $this->api('POST', $this->url(), ['resources' => ['memory_mib' => 6144, 'cpu_limit' => 4]])->assertAccepted();
        $this->assertSame(['1.50', 1000], [$this->server->refresh()->cpu_limit, $this->server->disk_iops]);
    }

    public function test_resources_are_changed_and_the_rest_is_kept()
    {
        $this->api('POST', $this->url(), ['resources' => ['memory_mib' => 6144, 'ipv4_count' => 2], 'reboot' => true])->assertAccepted();

        $server = $this->server->refresh();
        $this->assertSame($this->package->id, $server->package_id, 'Without "package", the package stays.');
        $this->assertSame(6144, $server->memory_mib);
        $this->assertSame(2, $server->cpu_cores);
        $this->assertSame(2, $server->ipAddresses()->count());
        $this->assertFalse($server->reboot_required, 'Restarted right away.');
    }

    public function test_a_server_can_leave_its_package()
    {
        $this->api('POST', $this->url(), ['package' => null, 'resources' => ['cpu_cores' => 3]])->assertAccepted();

        $server = $this->server->refresh();
        $this->assertNull($server->package_id);
        $this->assertSame(3, $server->cpu_cores);
        $this->assertSame(4096, $server->memory_mib, 'Not given: kept.');
    }

    public function test_a_smaller_disk_is_kept()
    {
        $this->api('POST', $this->url(), ['resources' => ['disk_mib' => 10240]])->assertAccepted();

        $this->assertSame(40960, $this->server->refresh()->disk_mib);
    }

    public function test_a_package_without_the_servers_network_model_is_refused()
    {
        $routedOnly = Package::factory()->create(['network_models' => [NetworkModel::Routed]]);

        $this->api('POST', $this->url(), ['package' => $routedOnly->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['package' => 'This package does not allow the network model of the server.']);
    }

    public function test_a_full_node_is_unprocessable_and_a_running_task_a_conflict()
    {
        $this->node->forceFill(['memory_bytes' => 6 * 1024 ** 3, 'memory_overcommit' => 1])->save();

        $this->api('POST', $this->url(), ['resources' => ['memory_mib' => 8192]])
            ->assertUnprocessable()
            ->assertExactJson(['message' => "The server's node does not have enough free capacity for these resources."]);

        $running = new Task;
        $running->forceFill(['server_id' => $this->server->id, 'type' => TaskType::Reboot, 'status' => TaskStatus::Running, 'steps' => []])->save();

        $this->api('POST', $this->url(), ['resources' => ['cpu_cores' => 1]])->assertConflict();
        $this->assertSame(2, $this->server->refresh()->cpu_cores);
    }

    public function test_only_active_servers()
    {
        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();

        $this->api('POST', $this->url(), ['resources' => ['cpu_cores' => 4]])->assertConflict();
    }

    public function test_the_request_is_validated()
    {
        $this->api('POST', $this->url(), ['package' => 'unknown', 'resources' => ['cpu_cores' => 0, 'memory_mib' => 64], 'reboot' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['package', 'resources.cpu_cores', 'resources.memory_mib', 'reboot']);
    }
}
