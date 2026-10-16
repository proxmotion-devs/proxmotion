<?php

namespace Tests\Integration;

use App\Domain\Servers\ServerReinstaller;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\User;
use App\Support\Ssh\PublicKey;
use Tests\Integration\Concerns\ManagesTestServers;
use Tests\Unit\PublicKeyTest;

/**
 * Reinstalling a provisioned server (ADR 0004): the VM is deleted and cloned
 * again with the same VMID, MAC address and IP address, the anti-spoofing
 * ipset is back, the disks are new and the guest agent answers. Needs the
 * spare address PROXMOX_TEST_IPV4.
 */
class ReinstallTest extends IntegrationTestCase
{
    use ManagesTestServers;

    private const MARKER = '/root/proxmotion-before-reinstall';

    public function test_a_server_is_reinstalled_in_place()
    {
        $server = $this->provisionServer();
        $node = $server->node->name;
        $address = (string) $server->ipAddresses()->value('address');
        $macBefore = $this->mac($server);

        $this->waitForAgent($server->vmid, $node, 600);
        $agent = $this->client()->guestAgent($node);
        $agent->writeFile($server->vmid, self::MARKER, 'old system');

        $task = app(ServerReinstaller::class)->request(
            $server,
            $server->osTemplate()->firstOrFail(),
            [PublicKey::parse(PublicKeyTest::ED25519)],
            User::factory()->create(),
            null,
        );

        $this->assertSame(TaskStatus::Succeeded, $task->refresh()->status, 'reinstall: '.$task->error);
        $this->assertTrue($task->hasCompleted('wait_agent'));
        $this->assertNull($this->stepMessage($task->steps, 'wait_agent'), 'The guest agent of the new system did not answer.');

        $server->refresh();
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame('running', $this->vmStatus($server));
        $this->assertSame($macBefore, $this->mac($server), 'The MAC address changed.');
        $this->assertSame($address, (string) $server->ipAddresses()->value('address'));

        $config = $this->client()->qemu($node)->config($server->vmid) ?? [];
        $this->assertStringContainsString("ip={$address}/", (string) ($config['ipconfig0'] ?? ''));

        $entries = $this->client()->get("/nodes/{$node}/qemu/{$server->vmid}/firewall/ipset/ipfilter-net0");
        $ipset = array_map(fn ($cidr) => explode('/', (string) $cidr)[0], array_column(is_array($entries) ? $entries : [], 'cidr'));
        $this->assertContains($address, $ipset, 'The anti-spoofing ipset was not recreated.');

        $marker = $agent->run($server->vmid, ['test', '-e', self::MARKER]);
        $this->assertSame(1, $marker->exitCode, 'A file from the old system survived: the disks were not replaced.');
    }

    private function mac(Server $server): ?string
    {
        $net0 = (string) ($this->client()->qemu($server->node->name)->config($server->vmid)['net0'] ?? '');

        return preg_match('/=([0-9A-F]{2}(?::[0-9A-F]{2}){5})/i', $net0, $match) === 1 ? strtoupper($match[1]) : null;
    }

    /**
     * @param  list<array{key: string, message: string|null}>  $steps
     */
    private function stepMessage(array $steps, string $key): ?string
    {
        foreach ($steps as $step) {
            if ($step['key'] === $key) {
                return $step['message'];
            }
        }

        return null;
    }
}
