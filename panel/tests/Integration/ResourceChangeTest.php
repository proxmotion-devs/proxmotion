<?php

namespace Tests\Integration;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\User;
use App\Provisioning\ResourceChange;
use App\Provisioning\ResourceChanger;
use App\Provisioning\ServerSpec;
use App\Provisioning\TaskRunner;
use Tests\Integration\Concerns\ManagesTestServers;

/**
 * An upgrade of a running server on a real Proxmox: cores and memory wait for
 * the next start ("pending"), the disk and the guest's root file system grow
 * at once (ADR 0010), and a reboot through the panel applies the rest. Needs
 * no spare address (adopted clone).
 */
class ResourceChangeTest extends IntegrationTestCase
{
    use ManagesTestServers;

    public function test_cores_and_memory_wait_for_a_restart_and_the_disk_grows_at_once()
    {
        $server = $this->adoptTestVm('proxmotion-resize-test');
        $this->runTask($server, TaskType::Start);
        $node = $server->node->name;
        $qemu = $this->client()->qemu($node);
        // A reboot asks the guest to shut down; one that is still booting does not answer.
        $this->waitForAgent($server->vmid, $node, 600);
        $before = $server->refresh()->only(['cpu_cores', 'memory_mib', 'disk_mib']);
        // Size of the root file system in MiB, as the guest sees it.
        $rootMib = fn () => (int) preg_replace('/\D/', '', (string) last(explode("\n", trim($this->client()->guestAgent($node)->run($server->vmid, ['df', '-BM', '--output=size', '/'])->stdout))));
        $rootBefore = $rootMib();

        $spec = new ServerSpec(
            cpuCores: $before['cpu_cores'] + 1,
            memoryMib: $before['memory_mib'] + 512,
            diskMib: $before['disk_mib'] + 1024,
            ipv4Count: 0,
            ipv6Count: 0,
        );
        $task = app(ResourceChanger::class)->request($server, new ResourceChange($spec), User::factory()->create(), null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertTrue($server->refresh()->reboot_required, 'Proxmox applied cores/memory to the running VM at once: hotplug is on, or pending changes look different.');

        $pending = $qemu->pendingChanges($server->vmid);
        $this->assertSame($before['cpu_cores'] + 1, (int) ($pending['cores'] ?? 0), 'cores is not pending: '.json_encode($pending));
        $this->assertSame($before['memory_mib'] + 512, (int) ($pending['memory'] ?? 0), 'memory is not pending: '.json_encode($pending));

        $this->waitForGuest($server->vmid, fn ($guest) => $guest !== null && intdiv((int) $guest->diskBytes, 1024 * 1024) >= $before['disk_mib'] + 1024);

        // The default disk script grew the partition and file system at once (ADR 0010).
        $step = collect($task->steps)->firstWhere('key', 'guest_disk');
        $this->assertNotNull($step, 'The disk script did not run: '.json_encode($task->steps));
        $this->assertNull($step['message'], (string) $step['message']);
        $this->assertGreaterThanOrEqual($rootBefore + 900, $rootMib(), 'The root file system did not grow.');

        $this->runTask($server, TaskType::Reboot);

        $this->assertFalse($server->refresh()->reboot_required, 'The reboot did not apply the pending changes.');
        $this->assertSame([], $qemu->pendingChanges($server->vmid));
        $config = $qemu->config($server->vmid) ?? [];
        $this->assertSame($before['cpu_cores'] + 1, (int) ($config['cores'] ?? 0));
        $this->assertSame($before['memory_mib'] + 512, (int) ($config['memory'] ?? 0));
    }

    public function test_a_stopped_server_takes_the_change_at_once()
    {
        $server = $this->adoptTestVm('proxmotion-resize-stopped');
        $cores = $server->refresh()->cpu_cores;

        $spec = new ServerSpec(cpuCores: $cores + 1, memoryMib: $server->memory_mib, diskMib: $server->disk_mib, ipv4Count: 0, ipv6Count: 0);
        app(ResourceChanger::class)->request($server, new ResourceChange($spec), User::factory()->create(), null);

        $this->assertFalse($server->refresh()->reboot_required);
        $this->assertSame($cores + 1, (int) ($this->client()->qemu($server->node->name)->config($server->vmid)['cores'] ?? 0));
        $this->assertSame(TaskStatus::Succeeded, app(TaskRunner::class)->start($server, TaskType::Start)->refresh()->status);
    }
}
