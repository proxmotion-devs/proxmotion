<?php

namespace Tests\Integration;

use App\Domain\Migration\AdoptServer;
use App\Enums\NetworkModel;
use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use Tests\Integration\Concerns\ManagesTestServers;

/**
 * Adoption and power actions on a real VM: a clone of the test template is
 * adopted (which must not touch it), then started, shut down cleanly,
 * rebooted and stopped through PowerPipeline. Needs no spare address.
 */
class PowerTest extends IntegrationTestCase
{
    use ManagesTestServers;

    public function test_an_adopted_server_is_powered_through_the_panel()
    {
        $vmid = $this->cloneTestVm('proxmotion-power-test');
        $node = (string) $this->guest($vmid)?->node;
        $tasksBefore = $this->taskCount($node, $vmid);
        $configBefore = $this->client()->qemu($node)->config($vmid);

        $this->syncCluster();
        $server = app(AdoptServer::class)->adopt($this->cluster, $vmid, null, NetworkModel::Bridged, []);

        $this->assertSame(ServerOrigin::Adopted, $server->origin);
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame($tasksBefore, $this->taskCount($node, $vmid), 'Adoption started a Proxmox task.');
        $this->assertEquals($configBefore, $this->client()->qemu($node)->config($vmid), 'Adoption changed the VM config.');
        $this->assertSame('stopped', $this->vmStatus($server));

        $this->runTask($server, TaskType::Start);
        $this->assertSame('running', $this->vmStatus($server));
        // A clean shutdown needs a booted guest that reacts to ACPI or the agent.
        $this->waitForAgent($vmid, $node);

        $this->runTask($server, TaskType::Shutdown);
        $this->assertSame('stopped', $this->vmStatus($server), 'Shutdown did not stop the VM.');

        // Rebooting a stopped VM starts it.
        $this->runTask($server, TaskType::Reboot);
        $this->assertSame('running', $this->vmStatus($server));
        $this->waitForAgent($vmid, $node);

        $uptimeBefore = $this->uptime($node, $vmid);
        $this->runTask($server, TaskType::Reboot);
        $this->assertSame('running', $this->vmStatus($server));
        $this->assertLessThan($uptimeBefore, $this->uptime($node, $vmid), 'The running VM was not rebooted.');

        $this->runTask($server, TaskType::Stop);
        $this->assertSame('stopped', $this->vmStatus($server));

        // Power actions never change the server's status, and repeating one is a no-op.
        $this->runTask($server, TaskType::Stop);
        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
    }

    private function taskCount(string $node, int $vmid): int
    {
        $tasks = $this->client()->get("/nodes/{$node}/tasks", ['vmid' => $vmid, 'limit' => 500]);

        return is_array($tasks) ? count($tasks) : 0;
    }

    private function uptime(string $node, int $vmid): int
    {
        $status = $this->client()->get("/nodes/{$node}/qemu/{$vmid}/status/current");

        return is_array($status) ? (int) ($status['uptime'] ?? 0) : 0;
    }
}
