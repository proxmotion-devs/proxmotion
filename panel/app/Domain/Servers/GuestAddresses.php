<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\Task;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Configures a server's additional IP addresses inside the guest through the
 * QEMU guest agent (docs/adr/0009): the list, the interface's MAC address and
 * a fixed script from resources/guest are written, then the script runs.
 * Problems are recorded on the task step and never fail the operation.
 */
final readonly class GuestAddresses
{
    public const STEP = 'guest_addresses';

    /** How long creating a server waits for the guest agent of its first boot. */
    public const AGENT_TIMEOUT_SECONDS = 600;

    private const SCRIPT = '/usr/local/sbin/proxmotion-addresses';

    public function __construct(private ProxmoxClientFactory $clients) {}

    /**
     * The addresses cloud-init does not configure: all but the first of each
     * IP version, as "address/prefix" (routed IPv4 addresses are /32).
     *
     * @return list<string>
     */
    public function additional(Server $server): array
    {
        $lines = [];
        $seen = [];

        foreach ($server->ipAddresses()->with('pool')->orderBy('id')->get() as $address) {
            $version = $address->pool->version;

            if (! isset($seen[$version->value])) {
                $seen[$version->value] = true;

                continue;
            }

            $lines[] = $address->address.'/'.$this->prefix($server, $address, $version);
        }

        return $lines;
    }

    /**
     * Write and apply the list. With $waitForAgent, wait for the agent of a
     * freshly booted server first; otherwise one ping decides.
     */
    public function apply(Task $task, Server $server, bool $waitForAgent = false): void
    {
        $client = $this->clients->forCluster($server->cluster);
        $agent = $client->guestAgent($server->node->name);

        if (! ($waitForAgent ? $this->waitForAgent($server) : $agent->ping($server->vmid))) {
            $task->completeStep(self::STEP, 'The guest agent did not answer: configure the additional addresses inside the server.');

            return;
        }

        $mac = $this->macOf($client->qemu($server->node->name)->config($server->vmid) ?? []);

        if ($mac === null) {
            $task->completeStep(self::STEP, 'The MAC address of the network interface is unknown: configure the additional addresses inside the server.');

            return;
        }

        try {
            $agent->run($server->vmid, ['mkdir', '-p', '/etc/proxmotion']);
            $agent->writeFile($server->vmid, '/etc/proxmotion/mac', $mac."\n");
            $agent->writeFile($server->vmid, '/etc/proxmotion/addresses', implode("\n", $this->additional($server))."\n");
            $agent->writeFile($server->vmid, self::SCRIPT, (string) file_get_contents(resource_path('guest/proxmotion-addresses.sh')));
            $result = $agent->run($server->vmid, ['sh', self::SCRIPT], timeoutSeconds: 120);
        } catch (ProxmoxException $e) {
            $task->completeStep(self::STEP, 'The additional addresses could not be configured inside the server: '.$e->getMessage());

            return;
        }

        $task->completeStep(self::STEP, $result->succeeded()
            ? null
            : 'The additional addresses could not be configured inside the server: '.trim(strtok($result->stderr."\n", "\n") ?: 'exit code '.$result->exitCode));
    }

    private function waitForAgent(Server $server): bool
    {
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $deadline = Carbon::now()->addSeconds(self::AGENT_TIMEOUT_SECONDS);

        while (! $agent->ping($server->vmid)) {
            if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                return false;
            }

            Sleep::for(5)->seconds();
        }

        return true;
    }

    private function prefix(Server $server, IpAddress $address, IpVersion $version): int
    {
        if ($version === IpVersion::V4 && $server->network_model === NetworkModel::Routed) {
            return 32;
        }

        $parts = explode('/', $address->pool->network);

        return (int) ($parts[1] ?? ($version === IpVersion::V4 ? 32 : 128));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function macOf(array $config): ?string
    {
        $net0 = is_string($config['net0'] ?? null) ? $config['net0'] : '';

        return preg_match('/=([0-9A-F]{2}(?::[0-9A-F]{2}){5})/i', $net0, $match) === 1 ? strtolower($match[1]) : null;
    }
}
