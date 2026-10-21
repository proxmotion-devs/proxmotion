<?php

namespace Tests\Support;

use App\Domain\Apps\AppLogs;
use App\Domain\Servers\GuestScripts;
use App\Domain\Servers\ServerVerifier;
use App\Proxmox\Data\BootDisk;
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
    /** @var array<int, array{node: string, pool: string|null, template: bool, status: string, config: array<string, mixed>, ipset: list<string>|null, ipsets?: array<string, list<string>>, firewall: array<string, mixed>, rules: list<array<string, mixed>>}> ipset: ipfilter-net0, ipsets: the other IP sets by name */
    public array $vms = [];

    /** @var array<string, int> GET /cluster/firewall/options */
    public array $datacenterFirewall = ['enable' => 1];

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

    /** When set, linked clone requests (full=0) are refused with this message. */
    public ?string $refuseLinkedClone = null;

    /** When set, config changes with tags are refused with this message (limited user tag access). */
    public ?string $refuseTags = null;

    /** @var array<int, list<string>> UPIDs reported as running per VMID; they finish once polled */
    public array $activeTasks = [];

    /** @var list<string> UPIDs whose status was requested, in order */
    public array $polledTasks = [];

    /** @var array<string, int> per task type (qmclone, qmdestroy, qmstart…): status checks that still report it running */
    public array $runningPolls = [];

    /**
     * @var array<int, list<array<string, mixed>>> per VMID: what network-get-interfaces answers; by default
     *                                             loopback and eth0 with net0's MAC and the firewall IP set's addresses
     */
    public array $guestInterfaces = [];

    /** @var array<int, string> per VMID: what the ServerVerifier script prints; by default every check passes */
    public array $verifyOutput = [];

    /** @var array<int, list<list<string>>> per VMID: commands of the ServerVerifier checks (kept out of guestCommands) */
    public array $verifyCommands = [];

    /**
     * @var array<int, array<string, mixed>> per VMID: changes of a running VM that wait for its next start,
     *                                       like Proxmox without CPU/memory hotplug
     */
    public array $pending = [];

    /** @var array<int, bool> VMIDs whose guest agent does not answer */
    public array $agentDown = [];

    /** @var array<int, list<string>> agent commands (exec, file-read, …) each guest blocks, as RHEL-family qemu-ga does by default */
    public array $agentBlocked = [];

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

    /**
     * What "cloud-init status" prints per VM, one answer per check; the last repeats ("status: done" by default).
     *
     * @var array<int, list<string>>
     */
    public array $cloudInitStatus = [];

    /** @var array<int, int> how often cloud-init was checked per VM (kept out of guestCommands) */
    public array $cloudInitChecks = [];

    /** How often an app log was read (kept out of guestCommands). */
    public int $guestLogReads = 0;

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
            'ipsets' => [],
            'firewall' => [],
            'rules' => [],
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
            $path === '/cluster/firewall/options' => $this->ok($this->datacenterFirewall),
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
            $linked = (string) ($data['full'] ?? '1') === '0';

            if ($linked && isset($data['storage'])) {
                return Create::promiseFor(new Psr7Response(400, ['Content-Type' => 'application/json'], '{"data":null}', '1.1', "Parameter verification failed. storage: parameter 'storage' not allowed for linked clones"));
            }

            if ($linked && $this->refuseLinkedClone !== null) {
                return Create::promiseFor(new Psr7Response(500, ['Content-Type' => 'application/json'], '{"data":null}', '1.1', $this->refuseLinkedClone));
            }

            $this->vms[$newId] = [
                ...$this->vms[$vmid],
                'node' => (string) ($data['target'] ?? $node),
                'pool' => isset($data['pool']) ? (string) $data['pool'] : null,
                'template' => false,
                'status' => 'stopped',
            ];
            $this->vms[$newId]['config']['name'] = $data['name'];
            // A linked clone's volume names its base volume, as on thin LVM and ZFS.
            $this->vms[$newId]['config']['scsi0'] = $linked
                ? (string) preg_replace('/^([^:]+):(base-\d+-disk-\d+)/', "\$1:\$2/vm-{$newId}-disk-0", (string) $this->vms[$vmid]['config']['scsi0'])
                : str_replace("base-{$vmid}", "vm-{$newId}", (string) $this->vms[$vmid]['config']['scsi0']);

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
            $suffix === '/pending' && $method === 'GET' => $this->pendingOf($vmid),
            $suffix === '/resize' && $method === 'PUT' => $this->resize($vmid, $data),
            $suffix === '/status/current' => $this->ok(['status' => $vm['status']]),
            (bool) preg_match('#^/status/(start|stop|shutdown|reboot)$#', $suffix, $m) => $this->power($node, $vmid, $m[1]),
            $suffix === '/vncproxy' && $method === 'POST' => $this->vncProxy($node, $vmid),
            str_starts_with($suffix, '/agent/') => $this->agent($vmid, substr($suffix, 7), $data),
            $suffix === '/firewall/options' && $method === 'GET' => $this->ok($vm['firewall']),
            $suffix === '/firewall/options' => $this->firewallOptions($vmid, $data),
            $suffix === '/firewall/rules' && $method === 'GET' => $this->ok(array_map(fn (array $rule, int $pos) => [...$rule, 'pos' => $pos], $vm['rules'], array_keys($vm['rules']))),
            $suffix === '/firewall/rules' => $this->addRule($vmid, $data),
            (bool) preg_match('#^/firewall/rules/(\d+)$#', $suffix, $m) && $method === 'DELETE' => $this->deleteRule($vmid, (int) $m[1]),
            $suffix === '/firewall/ipset' && $method === 'GET' => $this->ok($this->ipsetNames($vm)),
            $suffix === '/firewall/ipset' => $this->createIpset($vmid, (string) ($data['name'] ?? 'ipfilter-net0')),
            (bool) preg_match('#^/firewall/ipset/([A-Za-z0-9_-]+)$#', $suffix, $m) && $method === 'GET' => $this->ok(array_map(fn ($cidr) => ['cidr' => $cidr], $this->ipsetEntries($vm, $m[1]))),
            (bool) preg_match('#^/firewall/ipset/([A-Za-z0-9_-]+)$#', $suffix, $m) => $this->addToIpset($vmid, (string) $data['cidr'], $m[1]),
            (bool) preg_match('#^/firewall/ipset/([A-Za-z0-9_-]+)/(.+)$#', $suffix, $m) && $method === 'DELETE' => $this->removeFromIpset($vmid, rawurldecode($m[2]), $m[1]),
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

        if (in_array($command, $this->agentBlocked[$vmid] ?? [], true)) {
            return Create::promiseFor(new Psr7Response(500, ['Content-Type' => 'application/json'], '{"data":null}', '1.1', "Agent error: Command guest-{$command} has been disabled: the command is not allowed"));
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

                // Reading an app log (AppGuest::readCommand(), Linux and Windows): a byte range of a guest file, base64-encoded.
                $read = match (true) {
                    ($commandLine[2] ?? null) === AppLogs::READ_SCRIPT => 4,
                    str_ends_with($commandLine[6] ?? '', '/read-log.ps1') => 7,
                    default => null,
                };

                if (($commandLine[3] ?? null) === ServerVerifier::SCRIPT_NAME || str_contains($commandLine[4] ?? '', 'Resolve-DnsName')) {
                    // The checks after a create or reinstall (kept out of guestCommands).
                    $this->verifyCommands[$vmid][] = $commandLine;
                    [$exitCode, $out, $err] = [0, $this->verifyOutput[$vmid] ?? $this->passingChecks($vmid, $commandLine), ''];
                } elseif ($commandLine === GuestScripts::CLOUD_INIT_STATUS_COMMAND) {
                    // The cloud-init check of a new Linux system (kept out of guestCommands).
                    $answers = $this->cloudInitStatus[$vmid] ?? ['status: done'];
                    [$exitCode, $out, $err] = [0, $answers[min($this->cloudInitChecks[$vmid] ?? 0, count($answers) - 1)]."\n", ''];
                    $this->cloudInitChecks[$vmid] = ($this->cloudInitChecks[$vmid] ?? 0) + 1;
                } elseif ($read !== null) {
                    $this->guestLogReads++;
                    $file = $this->guestFiles[$vmid][$commandLine[$read]] ?? null;
                    [$exitCode, $out, $err] = $file === null
                        ? [1, '', 'No such file or directory']
                        : [0, base64_encode(substr($file, (int) $commandLine[$read + 1], (int) $commandLine[$read + 2])), ''];
                } else {
                    $this->guestCommands[] = ['vmid' => $vmid, 'command' => $commandLine, 'input' => $input];
                    [$exitCode, $out, $err] = $this->execHandler !== null ? ($this->execHandler)($commandLine, $input) : [0, '', ''];
                }
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
            case 'network-get-interfaces':
                return $this->ok(['result' => $this->guestInterfaces[$vmid] ?? $this->defaultInterfaces($vmid)]);
            case 'set-user-password':
                $this->guestPasswords[$vmid][(string) $data['username']] = (string) $data['password'];

                return $this->ok(['result' => []]);
            default:
                return Http::response(['data' => null], 501);
        }
    }

    /**
     * Loopback and eth0 with net0's MAC address and every address of the firewall IP set.
     *
     * @return list<array<string, mixed>>
     */
    private function defaultInterfaces(int $vmid): array
    {
        $config = $this->vms[$vmid]['config'];
        $mac = preg_match('/=([0-9A-F]{2}(:[0-9A-F]{2}){5})/i', (string) ($config['net0'] ?? ''), $match) === 1 ? strtolower($match[1]) : '';
        $addresses = array_map(fn (string $cidr) => [
            'ip-address' => explode('/', $cidr)[0],
            'ip-address-type' => str_contains($cidr, ':') ? 'ipv6' : 'ipv4',
        ], $this->vms[$vmid]['ipset'] ?? []);

        return [
            ['name' => 'lo', 'hardware-address' => '00:00:00:00:00:00', 'ip-addresses' => [['ip-address' => '127.0.0.1', 'ip-address-type' => 'ipv4']]],
            ['name' => 'eth0', 'hardware-address' => $mac, 'ip-addresses' => $addresses],
        ];
    }

    /**
     * What the ServerVerifier script prints when everything is as the panel
     * set it up: the VM name as hostname, the boot disk's size, every gateway
     * answering and DNS resolving.
     *
     * @param  list<string>  $command
     */
    private function passingChecks(int $vmid, array $command): string
    {
        $config = $this->vms[$vmid]['config'];
        $disk = BootDisk::fromConfig($config);
        $gateways = ($command[3] ?? null) === ServerVerifier::SCRIPT_NAME
            ? array_filter([$command[4] ?? '-', $command[5] ?? '-'], fn (string $gateway) => $gateway !== '-')
            : (preg_match_all("/'([0-9a-f.:]+)'/i", explode('$name', $command[4] ?? '')[0], $matches) > 0 ? $matches[1] : []);

        $lines = ['hostname='.($config['name'] ?? ''), 'disk='.($disk === null ? '' : $disk->sizeMib * 1024 * 1024)];

        foreach ($gateways as $gateway) {
            $lines[] = "gateway={$gateway} ok";
        }

        $lines[] = 'dns=ok';

        return implode("\n", $lines)."\n";
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
        $type = explode(':', $upid)[5] ?? '';

        if (($this->runningPolls[$type] ?? 0) > 0) {
            $this->runningPolls[$type]--;

            return $this->ok(['status' => 'running']);
        }

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
        if ($this->refuseTags !== null && array_key_exists('tags', $data)) {
            return Create::promiseFor(new Psr7Response(403, ['Content-Type' => 'application/json'], '{"data":null}', '1.1', $this->refuseTags));
        }

        foreach (array_filter(explode(',', (string) ($data['delete'] ?? ''))) as $key) {
            unset($this->vms[$vmid]['config'][$key]);
        }

        unset($data['delete']);

        foreach ($data as $key => $value) {
            // Without hotplug, Proxmox keeps CPU and memory changes of a running VM for its next start.
            if ($this->vms[$vmid]['status'] === 'running' && in_array($key, ['cores', 'sockets', 'memory'], true) && (string) ($this->vms[$vmid]['config'][$key] ?? '') !== (string) $value) {
                $this->pending[$vmid][$key] = $value;

                continue;
            }

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
        if (in_array($action, ['start', 'reboot'], true)) {
            // A (re)start applies pending changes.
            foreach ($this->pending[$vmid] ?? [] as $key => $value) {
                $this->vms[$vmid]['config'][$key] = $value;
            }

            unset($this->pending[$vmid]);
        }

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
        // Like Proxmox, a PUT changes only the options it names.
        $this->vms[$vmid]['firewall'] = [...$this->vms[$vmid]['firewall'], ...$data];

        return $this->ok(null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addRule(int $vmid, array $data): PromiseInterface
    {
        // New rules go to the top, as Proxmox puts them without `pos`.
        array_unshift($this->vms[$vmid]['rules'], $data);

        return $this->ok(null);
    }

    private function deleteRule(int $vmid, int $pos): PromiseInterface
    {
        array_splice($this->vms[$vmid]['rules'], $pos, 1);

        return $this->ok(null);
    }

    /**
     * @param  array<string, mixed>  $vm
     * @return list<array{name: string}>
     */
    private function ipsetNames(array $vm): array
    {
        /** @var array<string, list<string>> $others */
        $others = $vm['ipsets'] ?? [];
        $names = [...($vm['ipset'] === null ? [] : ['ipfilter-net0']), ...array_keys($others)];

        return array_map(fn (string $name) => ['name' => $name], $names);
    }

    /**
     * @param  array<string, mixed>  $vm
     * @return list<string>
     */
    private function ipsetEntries(array $vm, string $name): array
    {
        /** @var list<string> */
        return $name === 'ipfilter-net0' ? ($vm['ipset'] ?? []) : ($vm['ipsets'][$name] ?? []);
    }

    private function createIpset(int $vmid, string $name): PromiseInterface
    {
        if ($name === 'ipfilter-net0') {
            $this->vms[$vmid]['ipset'] = [];
        } else {
            $this->vms[$vmid]['ipsets'][$name] = [];
        }

        return $this->ok(null);
    }

    private function removeFromIpset(int $vmid, string $cidr, string $name): PromiseInterface
    {
        $keep = fn (array $entries) => array_values(array_filter($entries, fn (string $entry) => $entry !== $cidr));

        if ($name === 'ipfilter-net0') {
            $this->vms[$vmid]['ipset'] = $keep($this->vms[$vmid]['ipset'] ?? []);
        } else {
            $this->vms[$vmid]['ipsets'][$name] = $keep($this->vms[$vmid]['ipsets'][$name] ?? []);
        }

        return $this->ok(null);
    }

    /**
     * Like Proxmox: every config key with its value, and the pending value where one waits.
     */
    private function pendingOf(int $vmid): PromiseInterface
    {
        $entries = [];

        foreach ($this->vms[$vmid]['config'] as $key => $value) {
            $entries[$key] = ['key' => $key, 'value' => $value];
        }

        foreach ($this->pending[$vmid] ?? [] as $key => $value) {
            $entries[$key] = [...($entries[$key] ?? ['key' => $key]), 'pending' => $value];
        }

        return $this->ok(array_values($entries));
    }

    private function addToIpset(int $vmid, string $cidr, string $name): PromiseInterface
    {
        if ($name === 'ipfilter-net0') {
            $this->vms[$vmid]['ipset'][] = $cidr;
        } else {
            $this->vms[$vmid]['ipsets'][$name][] = $cidr;
        }

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
