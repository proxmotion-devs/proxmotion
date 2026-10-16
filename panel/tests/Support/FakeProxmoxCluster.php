<?php

namespace Tests\Support;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A tiny in-memory Proxmox for provisioning tests. It understands the endpoints
 * the panel uses for VMs (clone, config, resize, power, delete, firewall),
 * VMID checks and token permissions, and records every write request.
 */
class FakeProxmoxCluster
{
    /** @var array<int, array{node: string, pool: string|null, template: bool, status: string, config: array<string, mixed>, ipset: list<string>|null, firewall: array<string, mixed>}> */
    public array $vms = [];

    /** @var list<array{method: string, path: string, data: array<string, mixed>}> */
    public array $writes = [];

    /** @var array<string, list<string>> privileges per ACL path */
    public array $permissions = [];

    /**
     * Privileges per path that Proxmox reports with propagate = 0, like those a
     * VM inherits from its pool. They are granted all the same.
     *
     * @var array<string, list<string>>
     */
    public array $unpropagatedPermissions = [];

    /** When set, clone tasks of this VMID fail with this exit status. */
    public ?string $failClone = null;

    /** @var array<int, list<string>> UPIDs reported as running per VMID; they finish once polled */
    public array $activeTasks = [];

    /** @var list<string> UPIDs whose status was requested, in order */
    public array $polledTasks = [];

    /** @var array<int, bool> VMIDs whose guest agent does not answer */
    public array $agentDown = [];

    /** @var array<int, bool> VMIDs that /cluster/resources lists with maxdisk 0, as Proxmox can for stopped VMs */
    public array $unknownDiskSize = [];

    /** @var array<int, array<string, string>> files inside each guest, by path */
    public array $guestFiles = [];

    /** @var array<int, array<string, string>> passwords set through the agent, by user */
    public array $guestPasswords = [];

    /** @var list<array{vmid: int, command: list<string>, input: string|null}> commands run through the agent */
    public array $guestCommands = [];

    /**
     * Decides what a guest command returns: [exitcode, stdout, stderr]. Defaults to success without output.
     *
     * @var (callable(list<string>, string|null): array{int, string, string})|null
     */
    public $execHandler = null;

    /** How many exec-status polls report a command as still running. */
    public int $execPollsUntilExited = 0;

    /** @var array<int, array{exitcode: int, out: string, err: string, polls: int}> */
    private array $processes = [];

    public function addTemplate(int $vmid, string $node = 'pve1', ?string $pool = 'proxmotion', int $diskMib = 3584): self
    {
        $this->vms[$vmid] = [
            'node' => $node,
            'pool' => $pool,
            'template' => true,
            'status' => 'stopped',
            'config' => [
                'name' => "template-{$vmid}",
                'boot' => 'order=scsi0;ide2;net0',
                'scsi0' => "local-lvm:base-{$vmid}-disk-0,discard=on,size={$diskMib}M",
                'ide2' => 'local-lvm:vm-'.$vmid.'-cloudinit,media=cdrom',
                'net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0',
            ],
            'ipset' => null,
            'firewall' => [],
        ];

        return $this;
    }

    public function addVm(int $vmid, string $name, string $node = 'pve1', ?string $pool = 'proxmotion', string $status = 'running'): self
    {
        $this->addTemplate($vmid, $node, $pool);
        $this->vms[$vmid]['template'] = false;
        $this->vms[$vmid]['status'] = $status;
        $this->vms[$vmid]['config']['name'] = $name;

        return $this;
    }

    public function install(): self
    {
        Http::preventStrayRequests()->fake(fn (Request $request) => $this->handle($request));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function writePaths(): array
    {
        return array_map(fn (array $write) => "{$write['method']} {$write['path']}", $this->writes);
    }

    private function handle(Request $request): PromiseInterface
    {
        $method = $request->method();
        $url = parse_url($request->url());
        $path = (string) preg_replace('#^/api2/json#', '', $url['path'] ?? '');
        parse_str($url['query'] ?? '', $query);
        $data = $method === 'GET' || $method === 'DELETE' ? $query : $this->formData($request->body());

        if ($method !== 'GET') {
            $this->writes[] = ['method' => $method, 'path' => $path, 'data' => $data];
        }

        return match (true) {
            $path === '/version' => $this->ok(['version' => '9.2.2']),
            $path === '/cluster/nextid' => $this->nextId($query),
            // Proxmox maps each privilege to its propagate flag.
            $path === '/access/permissions' => $this->ok([$query['path'] => [
                ...array_fill_keys($this->permissions[$query['path']] ?? [], 1),
                ...array_fill_keys($this->unpropagatedPermissions[$query['path']] ?? [], 0),
            ]]),
            $path === '/cluster/resources' => $this->ok($this->resources()),
            (bool) preg_match('#^/nodes/([^/]+)/tasks$#', $path) => $this->ok(array_map(
                fn (string $upid) => ['upid' => $upid],
                $this->activeTasks[(int) ($query['vmid'] ?? 0)] ?? [],
            )),
            (bool) preg_match('#^/nodes/([^/]+)/tasks/(.+)/status$#', $path, $m) => $this->taskStatus(urldecode($m[2])),
            (bool) preg_match('#^/nodes/([^/]+)/qemu/(\d+)(.*)$#', $path, $m) => $this->qemu($method, $m[1], (int) $m[2], $m[3], $data),
            default => Http::response(['data' => null], 501),
        };
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function nextId(array $query): PromiseInterface
    {
        if (isset($query['vmid'])) {
            return isset($this->vms[(int) $query['vmid']])
                ? Http::response(['data' => null, 'errors' => ['vmid' => 'VM already exists']], 400)
                : $this->ok((string) $query['vmid']);
        }

        $id = 100;

        while (isset($this->vms[$id])) {
            $id++;
        }

        return $this->ok((string) $id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function qemu(string $method, string $node, int $vmid, string $suffix, array $data): PromiseInterface
    {
        $vm = $this->vms[$vmid] ?? null;

        if ($suffix === '/clone' && $method === 'POST') {
            $newId = (int) $data['newid'];
            $this->vms[$newId] = [
                ...$this->vms[$vmid],
                'node' => (string) ($data['target'] ?? $node),
                'pool' => isset($data['pool']) ? (string) $data['pool'] : null,
                'template' => false,
                'status' => 'stopped',
            ];
            $this->vms[$newId]['config']['name'] = $data['name'];
            $this->vms[$newId]['config']['scsi0'] = str_replace("base-{$vmid}", "vm-{$newId}", (string) $this->vms[$vmid]['config']['scsi0']);

            return $this->upid($node, $this->failClone !== null ? 'qmclone-fail' : 'qmclone', $newId);
        }

        if ($vm === null) {
            // Proxmox puts this message in the HTTP status line.
            return Create::promiseFor(new Psr7Response(500, ['Content-Type' => 'application/json'], '{"data":null}', '1.1', "Configuration file 'nodes/{$node}/qemu-server/{$vmid}.conf' does not exist"));
        }

        return match (true) {
            $suffix === '' && $method === 'DELETE' => $this->destroyVm($node, $vmid),
            $suffix === '/config' && $method === 'GET' => $this->ok($vm['config']),
            $suffix === '/config' && $method === 'PUT' => $this->updateConfig($vmid, $data),
            $suffix === '/resize' && $method === 'PUT' => $this->resize($vmid, $data),
            $suffix === '/status/current' => $this->ok(['status' => $vm['status']]),
            (bool) preg_match('#^/status/(start|stop|shutdown|reboot)$#', $suffix, $m) => $this->power($node, $vmid, $m[1]),
            $suffix === '/vncproxy' && $method === 'POST' => $this->vncProxy($node, $vmid),
            str_starts_with($suffix, '/agent/') => $this->agent($vmid, substr($suffix, 7), $data),
            $suffix === '/firewall/options' => $this->firewallOptions($vmid, $data),
            $suffix === '/firewall/ipset' && $method === 'GET' => $this->ok($vm['ipset'] === null ? [] : [['name' => 'ipfilter-net0']]),
            $suffix === '/firewall/ipset' => $this->createIpset($vmid),
            $suffix === '/firewall/ipset/ipfilter-net0' && $method === 'GET' => $this->ok(array_map(fn ($cidr) => ['cidr' => $cidr], $vm['ipset'] ?? [])),
            $suffix === '/firewall/ipset/ipfilter-net0' => $this->addToIpset($vmid, (string) $data['cidr']),
            default => Http::response(['data' => null], 501),
        };
    }

    /**
     * Parse a form body like Proxmox does: a repeated key becomes a list.
     *
     * @return array<string, mixed>
     */
    private function formData(string $body): array
    {
        $data = [];

        foreach ($body === '' ? [] : explode('&', $body) as $pair) {
            [$key, $value] = array_map('rawurldecode', array_pad(explode('=', str_replace('+', ' ', $pair), 2), 2, ''));

            if (! array_key_exists($key, $data)) {
                $data[$key] = $value;
            } else {
                $data[$key] = [...(array) $data[$key], $value];
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function agent(int $vmid, string $command, array $data): PromiseInterface
    {
        if ($this->vms[$vmid]['status'] !== 'running' || ($this->agentDown[$vmid] ?? false)) {
            return Create::promiseFor(new Psr7Response(500, ['Content-Type' => 'application/json'], '{"data":null}', '1.1', 'QEMU guest agent is not running'));
        }

        switch ($command) {
            case 'ping':
                return $this->ok(['result' => []]);
            case 'file-write':
                $content = (string) $data['content'];
                $this->guestFiles[$vmid][(string) $data['file']] = ($data['encode'] ?? '1') === '0' ? (string) base64_decode($content, true) : $content;

                return $this->ok(null);
            case 'file-read':
                $file = (string) $data['file'];

                if (! isset($this->guestFiles[$vmid][$file])) {
                    return Create::promiseFor(new Psr7Response(500, ['Content-Type' => 'application/json'], '{"data":null}', '1.1', 'guest-file-open failed: No such file or directory'));
                }

                // Like Proxmox: one character per byte of the file.
                return $this->ok(['content' => mb_convert_encoding($this->guestFiles[$vmid][$file], 'UTF-8', 'ISO-8859-1')]);
            case 'exec':
                $commandLine = array_values((array) $data['command']);
                $input = isset($data['input-data']) ? (string) $data['input-data'] : null;
                $this->guestCommands[] = ['vmid' => $vmid, 'command' => $commandLine, 'input' => $input];
                [$exitCode, $out, $err] = $this->execHandler !== null ? ($this->execHandler)($commandLine, $input) : [0, '', ''];
                $pid = 1000 + count($this->processes);
                $this->processes[$pid] = ['exitcode' => $exitCode, 'out' => $out, 'err' => $err, 'polls' => 0];

                return $this->ok(['pid' => $pid]);
            case 'exec-status':
                $pid = (int) $data['pid'];
                $process = &$this->processes[$pid];

                if ($this->execPollsUntilExited > $process['polls']++) {
                    return $this->ok(['exited' => 0]);
                }

                return $this->ok(array_filter([
                    'exited' => 1,
                    'exitcode' => $process['exitcode'],
                    'out-data' => $process['out'],
                    'err-data' => $process['err'],
                ], fn ($value) => $value !== ''));
            case 'set-user-password':
                $this->guestPasswords[$vmid][(string) $data['username']] = (string) $data['password'];

                return $this->ok(['result' => []]);
            default:
                return Http::response(['data' => null], 501);
        }
    }

    private function vncProxy(string $node, int $vmid): PromiseInterface
    {
        if ($this->vms[$vmid]['status'] !== 'running') {
            return Create::promiseFor(new Psr7Response(500, ['Content-Type' => 'application/json'], '{"data":null}', '1.1', "VM {$vmid} not running"));
        }

        return $this->ok([
            'port' => '5900',
            'ticket' => "PVEVNC:{$vmid}:fake-ticket",
            'password' => 'fake-vnc-password',
            'cert' => '-----BEGIN CERTIFICATE-----',
            'user' => 'proxmotion@pve!panel',
            'upid' => "UPID:{$node}:00001234:00005678:65000000:vncproxy:{$vmid}:proxmotion@pve!panel:",
        ]);
    }

    private function upid(string $node, string $type, int $vmid): PromiseInterface
    {
        return $this->ok("UPID:{$node}:00001234:00ABCDEF:6512ABCD:{$type}:{$vmid}:proxmotion@pve!panel:");
    }

    private function taskStatus(string $upid): PromiseInterface
    {
        $failed = str_contains($upid, ':qmclone-fail:');
        $this->polledTasks[] = $upid;

        foreach ($this->activeTasks as $vmid => $upids) {
            $this->activeTasks[$vmid] = array_values(array_diff($upids, [$upid]));
        }

        return $this->ok(['status' => 'stopped', 'exitstatus' => $failed ? (string) $this->failClone : 'OK']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateConfig(int $vmid, array $data): PromiseInterface
    {
        foreach ($data as $key => $value) {
            $this->vms[$vmid]['config'][$key] = $value;
        }

        // Proxmox generates a MAC when none is given.
        $net0 = (string) ($this->vms[$vmid]['config']['net0'] ?? '');

        if ($net0 !== '' && ! preg_match('/^\w+=[0-9A-F:]{17}/i', $net0)) {
            $this->vms[$vmid]['config']['net0'] = preg_replace('/^(\w+)/', '$1=BC:24:11:'.sprintf('%02X:%02X:%02X', $vmid % 256, random_int(0, 255), random_int(0, 255)), $net0, 1);
        }

        return $this->ok(null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resize(int $vmid, array $data): PromiseInterface
    {
        $disk = (string) $data['disk'];
        $this->vms[$vmid]['config'][$disk] = preg_replace('/size=[^,]+/', 'size='.$data['size'], (string) $this->vms[$vmid]['config'][$disk]);

        return $this->ok(null);
    }

    private function power(string $node, int $vmid, string $action): PromiseInterface
    {
        $this->vms[$vmid]['status'] = in_array($action, ['start', 'reboot'], true) ? 'running' : 'stopped';

        return $this->upid($node, "qm{$action}", $vmid);
    }

    private function destroyVm(string $node, int $vmid): PromiseInterface
    {
        unset($this->vms[$vmid]);

        return $this->upid($node, 'qmdestroy', $vmid);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function firewallOptions(int $vmid, array $data): PromiseInterface
    {
        $this->vms[$vmid]['firewall'] = $data;

        return $this->ok(null);
    }

    private function createIpset(int $vmid): PromiseInterface
    {
        $this->vms[$vmid]['ipset'] = [];

        return $this->ok(null);
    }

    private function addToIpset(int $vmid, string $cidr): PromiseInterface
    {
        $this->vms[$vmid]['ipset'][] = $cidr;

        return $this->ok(null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resources(): array
    {
        $list = [];

        foreach ($this->vms as $vmid => $vm) {
            $list[] = [
                'vmid' => $vmid,
                'node' => $vm['node'],
                'type' => 'qemu',
                'name' => $vm['config']['name'],
                'template' => $vm['template'] ? 1 : 0,
                'status' => $vm['status'],
                'pool' => $vm['pool'],
                // Taken from the config where the panel set it, like Proxmox does.
                'maxcpu' => (int) ($vm['config']['cores'] ?? 2),
                'maxmem' => (int) ($vm['config']['memory'] ?? 2048) * 1024 ** 2,
                'maxdisk' => ($this->unknownDiskSize[$vmid] ?? false) ? 0 : (preg_match('/size=(\d+)M/', (string) ($vm['config']['scsi0'] ?? ''), $size) === 1 ? (int) $size[1] * 1024 ** 2 : 20 * 1024 ** 3),
            ];
        }

        return $list;
    }

    private function ok(mixed $data): PromiseInterface
    {
        return Http::response(['data' => $data]);
    }
}
