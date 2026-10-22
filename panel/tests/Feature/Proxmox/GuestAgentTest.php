<?php

namespace Tests\Feature\Proxmox;

use App\Proxmox\Exceptions\GuestAgentException;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\ProxmoxConnection;
use App\Proxmox\Resources\GuestAgentResource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

class GuestAgentTest extends TestCase
{
    private FakeProxmoxCluster $proxmox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proxmox = (new FakeProxmoxCluster)->addVm(100, 'web1.example.com')->install();
    }

    private function agent(): GuestAgentResource
    {
        return app(ProxmoxClientFactory::class)->make(new ProxmoxConnection(
            hosts: ['pve1.example.com'],
            port: 8006,
            tokenId: 'proxmotion@pve!panel',
            tokenSecret: 'secret-token-value',
        ))->guestAgent('pve1');
    }

    public function test_ping_tells_whether_the_agent_answers()
    {
        $this->assertTrue($this->agent()->ping(100));

        $this->proxmox->agentDown[100] = true;
        $this->assertFalse($this->agent()->ping(100));

        $this->proxmox->agentDown = [];
        $this->proxmox->vms[100]['status'] = 'stopped';
        $this->assertFalse($this->agent()->ping(100));
    }

    public function test_commands_the_guest_blocks_say_how_to_allow_them()
    {
        // AlmaLinux 9's qemu-ga allows only a list of commands without guest-exec.
        $this->proxmox->agentBlocked[100] = ['exec'];

        $this->assertTrue($this->agent()->ping(100));

        try {
            $this->agent()->exec(100, ['true']);
            $this->fail('The blocked command was accepted.');
        } catch (GuestAgentException $e) {
            $this->assertStringContainsString('refuses guest-exec', $e->getMessage());
            $this->assertStringContainsString('/etc/sysconfig/qemu-ga', $e->getMessage());
        }
    }

    public function test_files_are_written_base64_encoded_and_read_back()
    {
        $content = "#!/bin/sh\necho 'hi'\n".random_bytes(64);

        $this->agent()->writeFile(100, '/root/script.sh', $content);

        $write = collect($this->proxmox->writes)->firstWhere('path', '/nodes/pve1/qemu/100/agent/file-write');
        $this->assertSame('0', $write['data']['encode']);
        $this->assertSame(base64_encode($content), $write['data']['content']);
        $this->assertSame($content, $this->proxmox->guestFiles[100]['/root/script.sh']);

        $this->proxmox->guestFiles[100]['/var/lib/status.json'] = '{"step":"done"}';
        $file = $this->agent()->readFile(100, '/var/lib/status.json');
        $this->assertSame('{"step":"done"}', $file->content);
        $this->assertFalse($file->truncated);
    }

    public function test_files_are_read_back_byte_for_byte()
    {
        // Proxmox sends one character per byte; seen on PVE 9.2.2.
        $binary = "\x00\x7f\x80\xf3\xff".random_bytes(256);
        $utf8 = '{"message":"Kurulum tamamlandı ✓"}';
        $this->proxmox->guestFiles[100]['/tmp/binary'] = $binary;
        $this->proxmox->guestFiles[100]['/tmp/utf8.json'] = $utf8;

        $this->assertSame(bin2hex($binary), bin2hex($this->agent()->readFile(100, '/tmp/binary')->content));
        $this->assertSame($utf8, $this->agent()->readFile(100, '/tmp/utf8.json')->content);
    }

    public function test_files_larger_than_one_write_are_refused_before_calling_proxmox()
    {
        $this->expectException(GuestAgentException::class);

        try {
            $this->agent()->writeFile(100, '/root/big', str_repeat('a', GuestAgentResource::MAX_WRITE_BYTES + 1));
        } finally {
            $this->assertSame([], $this->proxmox->writePaths());
        }
    }

    public function test_the_command_is_sent_as_a_list_without_a_shell()
    {
        $this->agent()->exec(100, ['cat', '/etc/os-release'], input: 'stdin data');

        $this->assertSame([['vmid' => 100, 'command' => ['cat', '/etc/os-release'], 'input' => 'stdin data']], $this->proxmox->guestCommands);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/agent/exec')
            && str_starts_with($request->body(), 'command=cat&command=%2Fetc%2Fos-release&'));
    }

    public function test_run_waits_until_the_command_exits()
    {
        Sleep::fake(syncWithCarbon: true);
        $this->proxmox->execPollsUntilExited = 2;
        $this->proxmox->execHandler = fn (array $command) => [0, "Debian GNU/Linux 12\n", ''];

        $status = $this->agent()->run(100, ['cat', '/etc/debian_version']);

        $this->assertTrue($status->succeeded());
        $this->assertSame("Debian GNU/Linux 12\n", $status->stdout);
        Sleep::assertSleptTimes(2);
    }

    public function test_run_reports_failures_and_timeouts()
    {
        Sleep::fake(syncWithCarbon: true);
        $this->proxmox->execHandler = fn () => [3, '', 'not found'];

        $status = $this->agent()->run(100, ['false']);
        $this->assertFalse($status->succeeded());
        $this->assertSame(3, $status->exitCode);
        $this->assertSame('not found', $status->stderr);

        $this->proxmox->execPollsUntilExited = 1000;
        $this->expectException(GuestAgentException::class);
        $this->agent()->run(100, ['sleep', '600'], timeoutSeconds: 5);
    }

    public function test_input_larger_than_the_agent_accepts_is_refused()
    {
        $this->expectException(GuestAgentException::class);

        $this->agent()->exec(100, ['cat'], str_repeat('a', GuestAgentResource::MAX_INPUT_BYTES + 1));
    }

    public function test_user_passwords_can_be_set()
    {
        $this->agent()->setUserPassword(100, 'root', 'n3w-p@ss word&=');

        $this->assertSame('n3w-p@ss word&=', $this->proxmox->guestPasswords[100]['root']);
    }
}
