<?php

namespace Tests\Integration;

/**
 * What happens inside a running guest when its cloud-init ipconfig0 changes
 * and it restarts: does cloud-init see a new instance (new instance id, new
 * SSH host keys), and does the new address get configured? Decides how a
 * primary address change is applied (docs/adr/0011). Uses documentation
 * addresses (192.0.2.0/24) on a clone; nothing leaves the VM.
 */
class CloudInitIdentityTest extends IntegrationTestCase
{
    public function test_changing_ipconfig0_and_restarting()
    {
        $vmid = $this->cloneTestVm('proxmotion-cloudinit-test');
        $node = $this->node();
        $client = $this->client();
        $qemu = $client->qemu($node);
        $agent = $client->guestAgent($node);

        $qemu->updateConfig($vmid, ['ipconfig0' => 'ip=192.0.2.20/24,gw=192.0.2.1']);
        $client->taskWaiter()->wait($qemu->power($vmid, 'start'), timeoutSeconds: 300);
        $this->waitForAgent($vmid, $node, 600);

        $read = fn (array $command) => trim($agent->run($vmid, $command)->stdout);
        $observe = fn () => [
            'instance_id' => $read(['cat', '/var/lib/cloud/data/instance-id']),
            'host_key' => $read(['cat', '/etc/ssh/ssh_host_ed25519_key.pub']),
            'addresses' => $read(['ip', '-o', '-4', 'addr', 'show', 'scope', 'global']),
        ];

        $os = $read(['sh', '-c', '. /etc/os-release && echo "$PRETTY_NAME"; cloud-init --version 2>&1']);
        $before = $observe();
        $this->assertStringContainsString('192.0.2.20/24', $before['addresses'], 'cloud-init did not configure the first address.');

        $qemu->updateConfig($vmid, ['ipconfig0' => 'ip=192.0.2.21/24,gw=192.0.2.1']);
        $client->taskWaiter()->wait($qemu->power($vmid, 'reboot'), timeoutSeconds: 300);
        sleep(15);
        $this->waitForAgent($vmid, $node, 600);
        $after = $observe();

        fwrite(STDERR, "\n".implode("\n", [
            'guest: '.str_replace("\n", ' / ', $os),
            'instance id changed: '.($before['instance_id'] !== $after['instance_id'] ? 'yes' : 'no'),
            'SSH host key changed: '.($before['host_key'] !== $after['host_key'] ? 'yes' : 'no'),
            'new address configured: '.(str_contains($after['addresses'], '192.0.2.21/24') ? 'yes' : 'no'),
            'old address still there: '.(str_contains($after['addresses'], '192.0.2.20/24') ? 'yes' : 'no'),
        ])."\n");

        // Seen on Debian 13 with cloud-init 25.1.4 (2026-10-17): a new instance with
        // new SSH host keys. That is why the panel never changes ipconfig0 of an
        // existing server (docs/adr/0011); if this ever fails, revisit that ADR.
        $this->assertNotSame($before['instance_id'], $after['instance_id'], 'cloud-init kept its instance id.');
        $this->assertNotSame($before['host_key'], $after['host_key'], 'The SSH host keys were kept.');
        $this->assertStringContainsString('192.0.2.21/24', $after['addresses']);
    }
}
