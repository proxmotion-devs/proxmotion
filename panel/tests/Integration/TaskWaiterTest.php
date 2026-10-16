<?php

namespace Tests\Integration;

/**
 * waitForVm against a task that is really running: a shutdown started like
 * one from the Proxmox UI is listed as active, and waiting for it lets the
 * next operation find the VM unlocked.
 */
class TaskWaiterTest extends IntegrationTestCase
{
    public function test_wait_for_vm_waits_for_a_running_task()
    {
        $vmid = $this->cloneTestVm('proxmotion-waiter-test');
        $node = (string) $this->guest($vmid)?->node;
        $client = $this->client();
        $qemu = $client->qemu($node);

        $client->taskWaiter()->wait($qemu->power($vmid, 'start'), timeoutSeconds: 300);
        $this->waitForAgent($vmid, $node);

        $shutdown = $qemu->power($vmid, 'shutdown');
        $active = array_map(fn ($upid) => $upid->value, $client->tasks()->activeForVm($node, $vmid));
        $this->assertContains($shutdown->value, $active, 'The running shutdown is not listed as an active task.');

        $client->taskWaiter()->waitForVm($node, $vmid, timeoutSeconds: 300);

        $this->assertFalse($client->tasks()->status($shutdown)['running']);
        $this->assertSame([], $client->tasks()->activeForVm($node, $vmid));
        $this->assertSame('stopped', $qemu->status($vmid)['status']);
    }
}
