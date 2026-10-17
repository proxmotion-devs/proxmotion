<?php

namespace Tests\Integration;

/**
 * What cloud-init does with a hostname changed inside a running guest, and
 * with a renamed Proxmox VM (the source of cloud-init's hostname). Decides
 * how a hostname change is applied (docs/adr/0012). Documentation addresses
 * on a clone; nothing leaves the VM.
 */
class HostnameIdentityTest extends IntegrationTestCase
{
    public function test_changing_the_hostname_inside_and_renaming_the_vm()
    {
        $vmid = $this->cloneTestVm('proxmotion-hostname-test');
        $node = $this->node();
        $client = $this->client();
        $qemu = $client->qemu($node);
        $agent = $client->guestAgent($node);

        $qemu->updateConfig($vmid, ['ipconfig0' => 'ip=192.0.2.30/24,gw=192.0.2.1']);
        $client->taskWaiter()->wait($qemu->power($vmid, 'start'), timeoutSeconds: 300);
        $this->waitForAgent($vmid, $node, 600);

        $read = fn (array $command) => trim($agent->run($vmid, $command)->stdout);
        $observe = fn () => [
            'instance_id' => $read(['cat', '/var/lib/cloud/data/instance-id']),
            'host_key' => $read(['cat', '/etc/ssh/ssh_host_ed25519_key.pub']),
            'hostname' => $read(['hostname']),
            'hosts' => $read(['sh', '-c', 'grep -v "^#" /etc/hosts | grep -v "^$" | tr "\n" ";"']),
        ];
        $reboot = function () use ($client, $qemu, $vmid, $node) {
            $client->taskWaiter()->wait($qemu->power($vmid, 'reboot'), timeoutSeconds: 300);
            sleep(15);
            $this->waitForAgent($vmid, $node, 600);
        };

        $initial = $observe();

        // 1. Inside the guest only, with cloud-init told to keep the hostname.
        $agent->run($vmid, ['hostnamectl', 'set-hostname', 'renamed-inside']);
        $agent->writeFile($vmid, '/etc/cloud/cloud.cfg.d/99-proxmotion-hostname.cfg', "preserve_hostname: true\n");
        $reboot();
        $inside = $observe();

        // 2. The Proxmox VM renamed (cloud-init's hostname source).
        $qemu->updateConfig($vmid, ['name' => 'renamed-vm']);
        $reboot();
        $renamed = $observe();

        fwrite(STDERR, "\n".implode("\n", [
            'initial: hostname='.$initial['hostname'].' hosts='.$initial['hosts'],
            'after the change inside + preserve_hostname + reboot: hostname='.$inside['hostname'].' hosts='.$inside['hosts']
                .' instance id changed='.($inside['instance_id'] !== $initial['instance_id'] ? 'yes' : 'no'),
            'after renaming the VM + reboot: hostname='.$renamed['hostname'].' hosts='.$renamed['hosts']
                .' instance id changed='.($renamed['instance_id'] !== $inside['instance_id'] ? 'yes' : 'no')
                .' SSH host key changed='.($renamed['host_key'] !== $inside['host_key'] ? 'yes' : 'no'),
        ])."\n");

        // Seen on Debian 13 with cloud-init 25.1.4 (2026-10-17), the basis of docs/adr/0012:
        // preserve_hostname keeps the name, cloud-init still rewrites /etc/hosts at every
        // boot, and renaming the VM makes cloud-init see a new instance.
        $this->assertSame('renamed-inside', $inside['hostname']);
        $this->assertSame($initial['instance_id'], $inside['instance_id']);
        $this->assertStringNotContainsString('renamed-inside', $inside['hosts'], 'cloud-init no longer rewrites /etc/hosts: the boot unit may be unnecessary.');
        $this->assertNotSame($inside['instance_id'], $renamed['instance_id'], 'Renaming the VM kept the instance id: renaming may be safe now.');
        $this->assertNotSame($inside['host_key'], $renamed['host_key']);
    }
}
