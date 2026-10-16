<?php

namespace Tests\Feature\Proxmox;

use App\Proxmox\Data\Upid;
use App\Proxmox\Exceptions\ProxmoxAuthenticationFailed;
use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\Exceptions\ProxmoxTaskFailed;
use App\Proxmox\Exceptions\ProxmoxTlsVerificationFailed;
use App\Proxmox\Exceptions\ProxmoxUnreachable;
use App\Proxmox\ProxmoxClient;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\ProxmoxConnection;
use App\Proxmox\TaskWaiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesProxmox;
use Tests\TestCase;

class ProxmoxClientTest extends TestCase
{
    use FakesProxmox;

    private const UPID = 'UPID:pve1:0001A2B3:0C4D5E6F:6512ABCD:qmclone:100:proxmotion@pve!panel:';

    private function client(array $hosts = ['pve1.example.com']): ProxmoxClient
    {
        return app(ProxmoxClientFactory::class)->make(new ProxmoxConnection(
            hosts: $hosts,
            port: 8006,
            tokenId: 'proxmotion@pve!panel',
            tokenSecret: 'secret-token-value',
        ));
    }

    public function test_it_sends_the_api_token_and_unwraps_data()
    {
        $this->fakeProxmox();

        $this->assertSame('8.4.1', $this->client()->version());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://pve1.example.com:8006/api2/json/version'
            && $request->hasHeader('Authorization', 'PVEAPIToken=proxmotion@pve!panel=secret-token-value'));
    }

    public function test_it_wraps_ipv6_hosts_in_brackets()
    {
        $this->fakeProxmox();

        $this->client(['2001:db8::10'])->version();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://[2001:db8::10]:8006/api2/json/'));
    }

    public function test_it_fails_over_to_the_next_host()
    {
        Http::preventStrayRequests()->fake([
            'https://pve1.example.com:8006/*' => fn () => throw new ConnectionException('Connection refused'),
            'https://pve2.example.com:8006/*' => $this->proxmoxFixture('version'),
        ]);

        $this->assertSame('8.4.1', $this->client(['pve1.example.com', 'pve2.example.com'])->version());
    }

    public function test_a_connection_that_could_not_be_established_is_tried_again_once()
    {
        Sleep::fake();
        $attempts = 0;
        Http::preventStrayRequests()->fake([
            '*' => function () use (&$attempts) {
                if (++$attempts === 1) {
                    throw new ConnectionException('cURL error 28: Connection timed out after 5003 milliseconds (see https://curl.se/libcurl/c/libcurl-errors.html)');
                }

                return $this->proxmoxFixture('version');
            },
        ]);

        $this->assertSame('8.4.1', $this->client()->post('/version')['version'] ?? null);
        $this->assertSame(2, $attempts, 'Not connected means not sent: even a POST may be sent again.');
        Sleep::assertSleptTimes(1);
    }

    public function test_a_timeout_after_the_request_was_sent_is_not_repeated()
    {
        $attempts = 0;
        Http::preventStrayRequests()->fake([
            '*' => function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received');
            },
        ]);

        try {
            $this->client()->post('/nodes/pve1/qemu/100/status/start');
            $this->fail('No exception.');
        } catch (ProxmoxUnreachable) {
        }

        $this->assertSame(1, $attempts, 'The request may have reached Proxmox: never send it twice.');
    }

    public function test_it_reports_when_no_host_is_reachable()
    {
        Http::preventStrayRequests()->fake([
            '*' => fn () => throw new ConnectionException('Connection refused'),
        ]);

        $this->expectException(ProxmoxUnreachable::class);
        $this->expectExceptionMessage('pve1.example.com, pve2.example.com');

        $this->client(['pve1.example.com', 'pve2.example.com'])->version();
    }

    public function test_certificate_errors_are_reported_as_tls_failures()
    {
        Http::preventStrayRequests()->fake([
            '*' => fn () => throw new ConnectionException('cURL error 60: SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)'),
        ]);

        $this->expectException(ProxmoxTlsVerificationFailed::class);
        $this->expectExceptionMessage('pve1.example.com');

        $this->client()->version();
    }

    public function test_an_invalid_token_raises_an_authentication_error_without_leaking_the_secret()
    {
        Http::preventStrayRequests()->fake(['*' => Http::response(['data' => null], 401)]);

        try {
            $this->client()->version();
            $this->fail('Expected an exception.');
        } catch (ProxmoxAuthenticationFailed $e) {
            $this->assertStringNotContainsString('secret-token-value', $e->getMessage());
        }
    }

    public function test_request_errors_include_the_proxmox_reason_and_details()
    {
        Http::preventStrayRequests()->fake([
            '*' => Http::response(['data' => null, 'errors' => ['vmid' => 'invalid format']], 400, []),
        ]);

        try {
            $this->client()->post('/nodes/pve1/qemu', ['vmid' => 'abc']);
            $this->fail('Expected an exception.');
        } catch (ProxmoxRequestFailed $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringContainsString('vmid: invalid format', $e->getMessage());
        }
    }

    public function test_it_parses_cluster_resources()
    {
        $this->fakeProxmox();
        $client = $this->client();

        $status = $client->cluster()->status();
        $this->assertTrue($status->isCluster());
        $this->assertSame('dc1', $status->clusterName);
        $this->assertSame(['pve1', 'pve2'], $status->nodes);

        $nodes = $client->nodes()->list();
        $this->assertSame(32, $nodes[0]->cpuCores);
        $this->assertTrue($nodes[0]->online);
        $this->assertFalse($nodes[1]->online);
        $this->assertNull($nodes[1]->cpuCores);

        $storages = $client->cluster()->storages();
        $this->assertSame(['images', 'rootdir'], $storages[1]->content);
        $this->assertTrue($storages[2]->shared);

        $bridges = $client->nodes()->bridges('pve1');
        $this->assertSame('vmbr0', $bridges[0]->name);
        $this->assertTrue($bridges[0]->vlanAware);
        $this->assertSame('Public', $bridges[0]->comments);
        $this->assertNull($bridges[1]->cidr);
    }

    public function test_it_detects_standalone_nodes()
    {
        $this->fakeProxmox(['*/cluster/status' => $this->proxmoxFixture('cluster-status-standalone')]);

        $this->assertFalse($this->client()->cluster()->status()->isCluster());
    }

    public function test_upids_are_parsed()
    {
        $upid = Upid::parse(self::UPID);

        $this->assertSame('pve1', $upid->node);
        $this->assertSame('qmclone', $upid->type);
        $this->assertSame('100', $upid->id);
    }

    public function test_task_waiter_polls_until_the_task_succeeds()
    {
        Sleep::fake(syncWithCarbon: true);
        Http::preventStrayRequests()->fake([
            '*/tasks/*/status' => Http::sequence()
                ->push(['data' => ['status' => 'running']])
                ->push(['data' => ['status' => 'running']])
                ->push(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        ]);

        (new TaskWaiter($this->client()))->wait(Upid::parse(self::UPID), pollSeconds: 2);

        Sleep::assertSleptTimes(2);
        Http::assertSentCount(3);
    }

    public function test_task_waiter_accepts_warnings_as_success()
    {
        Http::preventStrayRequests()->fake([
            '*/tasks/*/status' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'WARNINGS: 1']]),
        ]);

        (new TaskWaiter($this->client()))->wait(Upid::parse(self::UPID));

        $this->addToAssertionCount(1);
    }

    public function test_task_waiter_reports_failed_tasks()
    {
        Http::preventStrayRequests()->fake([
            '*/tasks/*/status' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'clone failed: no space left']]),
        ]);

        $this->expectException(ProxmoxTaskFailed::class);
        $this->expectExceptionMessage('clone failed: no space left');

        (new TaskWaiter($this->client()))->wait(Upid::parse(self::UPID));
    }

    public function test_task_waiter_times_out()
    {
        Sleep::fake(syncWithCarbon: true);
        Http::preventStrayRequests()->fake([
            '*/tasks/*/status' => Http::response(['data' => ['status' => 'running']]),
        ]);

        $this->expectException(ProxmoxTaskFailed::class);
        $this->expectExceptionMessage('did not finish within 10 seconds');

        (new TaskWaiter($this->client()))->wait(Upid::parse(self::UPID), timeoutSeconds: 10, pollSeconds: 2);
    }
}
