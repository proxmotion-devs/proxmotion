<?php

namespace Tests\Integration;

use App\Domain\Servers\HostnameChanger;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\User;
use Tests\Integration\Concerns\ManagesTestServers;

/**
 * ADR 0012 on a real guest: the hostname script renames the system, and its
 * boot unit keeps /etc/hosts on the new name although cloud-init rewrites it
 * at every boot. The Proxmox VM keeps its name.
 */
class HostnameTest extends IntegrationTestCase
{
    use ManagesTestServers;

    public function test_the_hostname_changes_inside_and_survives_a_reboot()
    {
        $server = $this->adoptTestVm('proxmotion-rename-test');
        $node = $server->node->name;
        $this->runTask($server, TaskType::Start);
        $this->waitForAgent($server->vmid, $node, 600);

        $agent = $this->client()->guestAgent($node);
        $read = fn (array $command) => trim($agent->run($server->vmid, $command)->stdout);
        $vmName = $this->client()->qemu($node)->config($server->vmid)['name'] ?? null;

        $task = app(HostnameChanger::class)->request($server->refresh(), 'renamed.example.com', false, User::factory()->create(), null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $step = collect($task->steps)->firstWhere('key', 'guest_hostname');
        $this->assertNotNull($step, (string) json_encode($task->steps));
        $this->assertNull($step['message'], (string) $step['message']);
        $this->assertSame('renamed.example.com', $read(['hostname']));
        $this->assertStringContainsString('127.0.1.1 renamed.example.com renamed', $read(['cat', '/etc/hosts']));

        $this->runTask($server, TaskType::Reboot);
        $this->waitForAgent($server->vmid, $node, 600);
        sleep(10);

        $this->assertSame('renamed.example.com', $read(['hostname']), 'cloud-init set the old name again.');
        $this->assertStringContainsString('127.0.1.1 renamed.example.com renamed', $read(['cat', '/etc/hosts']), 'The boot unit did not fix /etc/hosts.');
        $this->assertSame($vmName, $this->client()->qemu($node)->config($server->vmid)['name'] ?? null, 'The VM must keep its name.');
    }
}
