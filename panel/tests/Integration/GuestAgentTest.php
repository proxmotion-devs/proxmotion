<?php

namespace Tests\Integration;

use App\Proxmox\Resources\GuestAgentResource;
use Illuminate\Support\Str;

/**
 * The guest agent client against a real VM: one full clone of the test
 * template (deleted afterwards) runs every agent call the panel uses.
 * Needs a template with qemu-guest-agent and a Linux guest with sh, cat and passwd.
 */
class GuestAgentTest extends IntegrationTestCase
{
    public function test_the_guest_agent_calls_the_panel_uses_work()
    {
        $vmid = $this->cloneTestVm('proxmotion-agent-test');
        $node = (string) $this->guest($vmid)?->node;
        $client = $this->client();
        $client->taskWaiter()->wait($client->qemu($node)->power($vmid, 'start'), timeoutSeconds: 300);
        $this->waitForAgent($vmid, $node);

        $agent = $client->guestAgent($node);
        $this->assertTrue($agent->ping($vmid));

        $this->assertFileRoundTrip($agent, $vmid);
        $this->assertCommandsRun($agent, $vmid);
        $this->assertRootPasswordCanBeSet($agent, $vmid);
    }

    /**
     * file-write with encode=0 (base64 from the panel) and file-read decoding,
     * with binary content and the largest single write.
     */
    private function assertFileRoundTrip(GuestAgentResource $agent, int $vmid): void
    {
        $binary = "#!/bin/sh\necho ok\n".random_bytes(256);
        $agent->writeFile($vmid, '/tmp/proxmotion-binary', $binary);
        $this->assertSame(bin2hex($binary), bin2hex($agent->readFile($vmid, '/tmp/proxmotion-binary')->content), 'Binary content changed on the way.');

        $large = str_repeat('x', GuestAgentResource::MAX_WRITE_BYTES);
        $agent->writeFile($vmid, '/tmp/proxmotion-large', $large);
        $read = $agent->readFile($vmid, '/tmp/proxmotion-large');
        $this->assertSame(strlen($large), strlen($read->content));
        $this->assertFalse($read->truncated);
    }

    /**
     * exec with the command as repeated form keys, stdin via input-data,
     * exit codes and stderr.
     */
    private function assertCommandsRun(GuestAgentResource $agent, int $vmid): void
    {
        $osRelease = $agent->run($vmid, ['cat', '/etc/os-release']);
        $this->assertTrue($osRelease->succeeded());
        $this->assertStringContainsString('ID=', $osRelease->stdout);

        $stdin = $agent->run($vmid, ['cat'], input: "hello from proxmotion\n");
        $this->assertSame("hello from proxmotion\n", $stdin->stdout);

        $failing = $agent->run($vmid, ['sh', '-c', 'echo problem >&2; exit 3']);
        $this->assertTrue($failing->exited);
        $this->assertSame(3, $failing->exitCode);
        $this->assertStringContainsString('problem', $failing->stderr);
    }

    /**
     * set-user-password changes root's password (checked with passwd -S,
     * which reports "P"/"PS" for a usable password after locking it first).
     */
    private function assertRootPasswordCanBeSet(GuestAgentResource $agent, int $vmid): void
    {
        $agent->run($vmid, ['passwd', '-l', 'root']);
        $agent->setUserPassword($vmid, 'root', Str::password(20, symbols: false));

        $status = $agent->run($vmid, ['passwd', '-S', 'root']);
        $this->assertTrue($status->succeeded(), $status->stderr);
        // Debian/Ubuntu print "root P …", the RHEL family "root PS …".
        $this->assertMatchesRegularExpression('/^root PS? /', $status->stdout, 'root has no usable password after set-user-password.');
    }
}
