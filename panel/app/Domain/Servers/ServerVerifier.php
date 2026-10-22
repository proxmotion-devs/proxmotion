<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Domain\Settings\PanelSettings;
use App\Enums\IpVersion;
use App\Enums\OsPlatform;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\Task;
use App\Proxmox\Data\GuestNetworkInterface;
use App\Proxmox\Exceptions\GuestAgentException;
use App\Proxmox\Exceptions\GuestCommandDisabled;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\GuestAgentResource;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Checks from inside a server that it came up as the panel set it up
 * (docs/adr/0032 §6): its addresses are on its network interface, its
 * gateways answer, DNS resolves, its hostname is the server's and its root
 * file system has the disk's size. The result is kept on the server
 * (servers.verification) and shown on its page; a failed check only warns.
 *
 * Everything runs through the guest agent with fixed commands: the gateways
 * (addresses of the server's pools) and the DNS name (configuration) are
 * validated before they are handed over as arguments, never through a shell
 * string.
 */
final readonly class ServerVerifier
{
    /**
     * Prints hostname=, disk= (bytes of /), one gateway=<address> ok|fail per
     * gateway and dns=ok|fail. Arguments: IPv4 gateway, IPv6 gateway and DNS
     * name, "-" for none.
     */
    public const LINUX_SCRIPT = <<<'SH'
        echo "hostname=$(hostname 2> /dev/null)"
        echo "disk=$(df -B1 --output=size / 2> /dev/null | tail -n 1 | tr -d ' ')"
        for gateway in "$1" "$2"; do
            [ "$gateway" = - ] && continue
            if ping -c 3 -W 2 "$gateway" > /dev/null 2>&1; then echo "gateway=$gateway ok"; else echo "gateway=$gateway fail"; fi
        done
        if [ "$3" != - ]; then
            if getent hosts "$3" > /dev/null 2>&1; then echo "dns=ok"; else echo "dns=fail"; fi
        fi
        SH;

    /** The name the Linux script runs as ($0). */
    public const SCRIPT_NAME = 'proxmotion-verify';

    /** The same on Windows; {gateways} and {dns} are replaced by validated, quoted values. */
    public const WINDOWS_SCRIPT = <<<'PS'
        $ErrorActionPreference = 'SilentlyContinue'
        Write-Output ('hostname=' + [System.Net.Dns]::GetHostName())
        Write-Output ('disk=' + (Get-Volume -DriveLetter C).Size)
        foreach ($gateway in @({gateways})) {
            if (Test-Connection -ComputerName $gateway -Count 3 -Quiet) { Write-Output "gateway=$gateway ok" } else { Write-Output "gateway=$gateway fail" }
        }
        $name = {dns}
        if ($name -ne '') {
            if (Resolve-DnsName -Name $name -DnsOnly) { Write-Output 'dns=ok' } else { Write-Output 'dns=fail' }
        }
        PS;

    /** A root file system this much smaller than the disk still passes (partition table, file system overhead). */
    private const DISK_TOLERANCE = 0.9;

    public function __construct(
        private ProxmoxClientFactory $clients,
        private GuestScripts $guestScripts,
    ) {}

    /**
     * Run the checks and keep the result on the server.
     *
     * @return list<array{key: string, status: string, params: array<string, string>, detail: string|null}>
     *
     * @throws GuestAgentException when the guest agent does not answer
     */
    public function verify(Server $server): array
    {
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);

        if (! $agent->ping($server->vmid)) {
            throw GuestAgentException::notReachable($server->vmid);
        }

        $addresses = $server->ipAddresses()->with('pool')->get();
        $gateways = [];

        foreach (IpVersion::cases() as $version) {
            $gateway = $addresses->first(fn (IpAddress $address) => $address->pool->version === $version)?->pool->gateway;

            if ($gateway !== null && filter_var($gateway, FILTER_VALIDATE_IP) !== false) {
                $gateways[$version->value] = $gateway;
            }
        }

        $checks = [
            $this->addressCheck($agent, $server, array_values($addresses->map(fn (IpAddress $address) => $address->address)->all())),
            ...$this->guestChecks($agent, $server, $gateways),
        ];

        $server->forceFill(['verification' => ['checked_at' => Carbon::now()->toIso8601String(), 'checks' => $checks]])->save();

        return $checks;
    }

    /**
     * The verify step of a create or reinstall: never fails the operation.
     */
    public function verifyStep(Task $task, Server $server): void
    {
        $task->startStep('verify');

        try {
            $checks = $this->verify($server);
        } catch (Throwable $e) {
            if (! $e instanceof ProxmoxException) {
                report($e);
            }

            $task->completeStep('verify', 'The checks could not run: '.$e->getMessage());

            return;
        }

        $task->completeStep('verify', self::summary($checks));
    }

    /**
     * A verify task (Check again on the server page): an agent that does not
     * answer fails it; failed checks do not.
     */
    public function runTask(Task $task): void
    {
        $task->startStep('verify');
        $task->completeStep('verify', self::summary($this->verify($task->server)));
    }

    /**
     * @param  list<array{key: string, status: string, params: array<string, string>, detail: string|null}>  $checks
     * @return string|null null when every check passed
     */
    public static function summary(array $checks): ?string
    {
        $failed = array_filter($checks, fn (array $check) => $check['status'] === 'failed');

        if ($failed === []) {
            return null;
        }

        // "gateway 203.0.113.1", "dns example.com", "hostname", "disk".
        $names = array_map(fn (array $check) => match ($check['key']) {
            'gateway' => 'gateway '.$check['params']['address'],
            'dns' => 'dns '.$check['params']['name'],
            default => $check['key'],
        }, $failed);

        return 'Failed checks: '.implode(', ', $names).'. See the checks on the server page.';
    }

    /**
     * Every address of the server on the interface with its MAC address.
     *
     * @param  list<string>  $expected
     * @return array{key: string, status: string, params: array<string, string>, detail: string|null}
     */
    private function addressCheck(GuestAgentResource $agent, Server $server, array $expected): array
    {
        $check = fn (string $status, ?string $detail = null, array $params = []) => ['key' => 'addresses', 'status' => $status, 'params' => $params, 'detail' => $detail];

        try {
            $interfaces = $agent->networkInterfaces($server->vmid);
        } catch (GuestCommandDisabled) {
            return $check('skipped', 'The guest agent does not report network interfaces.');
        } catch (ProxmoxException) {
            return $check('skipped', 'The guest agent did not report its network interfaces.');
        }

        $mac = $server->mac_address === null ? null : strtolower($server->mac_address);
        $interface = array_values(array_filter($interfaces, fn (GuestNetworkInterface $interface) => $mac !== null && $interface->mac === $mac))[0] ?? null;

        if ($interface === null) {
            return $check('failed', 'No network interface has the MAC address :mac.', ['mac' => (string) $server->mac_address]);
        }

        $present = array_map(fn (string $address) => (string) inet_pton($address), array_filter($interface->addresses, fn (string $address) => filter_var($address, FILTER_VALIDATE_IP) !== false));
        $missing = array_values(array_filter($expected, fn (string $address) => ! in_array((string) inet_pton($address), $present, true)));

        return $missing === []
            ? $check('passed')
            : $check('failed', 'Missing on :interface: :addresses.', ['interface' => $interface->name, 'addresses' => implode(', ', $missing)]);
    }

    /**
     * Gateways, DNS, hostname and disk, in one fixed command.
     *
     * @param  array<int, string>  $gateways  per IP version (4, 6)
     * @return list<array{key: string, status: string, params: array<string, string>, detail: string|null}>
     */
    private function guestChecks(GuestAgentResource $agent, Server $server, array $gateways): array
    {
        $dns = (string) PanelSettings::get('verify_dns_name');
        $dns = preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $dns) === 1 ? strtolower($dns) : '';
        $windows = $this->guestScripts->platform($server) === OsPlatform::Windows;

        $command = $windows
            ? ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', strtr(self::WINDOWS_SCRIPT, [
                '{gateways}' => implode(', ', array_map(fn (string $gateway) => "'{$gateway}'", $gateways)),
                '{dns}' => "'{$dns}'",
            ])]
            : ['sh', '-c', self::LINUX_SCRIPT, self::SCRIPT_NAME, $gateways[IpVersion::V4->value] ?? '-', $gateways[IpVersion::V6->value] ?? '-', $dns === '' ? '-' : $dns];

        $skipped = fn (string $detail) => array_map(
            fn (string $key) => ['key' => $key, 'status' => 'skipped', 'params' => [], 'detail' => $detail],
            ['gateway', 'dns', 'hostname', 'disk'],
        );

        try {
            $result = $agent->run($server->vmid, $command, timeoutSeconds: 60);
        } catch (GuestCommandDisabled) {
            return $skipped('The guest agent does not run commands.');
        } catch (ProxmoxException) {
            return $skipped('The checks did not finish inside the server.');
        }

        $lines = [];
        $answers = [];

        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            if (str_starts_with($line, 'gateway=')) {
                [$address, $outcome] = array_pad(explode(' ', substr($line, 8), 2), 2, '');
                $answers[$address] = trim($outcome) === 'ok';
            } elseif (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $lines[$key] = trim($value);
            }
        }

        $checks = [];

        foreach ($gateways as $gateway) {
            $checks[] = ['key' => 'gateway', 'status' => ($answers[$gateway] ?? false) ? 'passed' : 'failed', 'params' => ['address' => $gateway], 'detail' => null];
        }

        if ($dns !== '') {
            $checks[] = ['key' => 'dns', 'status' => ($lines['dns'] ?? '') === 'ok' ? 'passed' : 'failed', 'params' => ['name' => $dns], 'detail' => null];
        }

        $checks[] = $this->hostnameCheck($server, $lines['hostname'] ?? '', $windows);
        $checks[] = $this->diskCheck($server, $lines['disk'] ?? '');

        return $checks;
    }

    /**
     * @return array{key: string, status: string, params: array<string, string>, detail: string|null}
     */
    private function hostnameCheck(Server $server, string $actual, bool $windows): array
    {
        $expected = strtolower($server->hostname);
        $short = explode('.', $expected)[0];
        $reported = strtolower($actual);
        // Windows keeps a computer name, the first label; its NetBIOS form has at most 15 characters.
        $passed = $reported !== '' && ($reported === $expected || $reported === $short || ($windows && $reported === substr($short, 0, 15)));

        $check = ['key' => 'hostname', 'status' => 'passed', 'params' => ['hostname' => $server->hostname], 'detail' => null];

        return match (true) {
            $actual === '' => [...$check, 'status' => 'skipped', 'detail' => 'The server did not report its hostname.'],
            $passed => $check,
            default => [...$check, 'status' => 'failed', 'params' => [...$check['params'], 'actual' => $actual], 'detail' => 'The server reports :actual.'],
        };
    }

    /**
     * @return array{key: string, status: string, params: array<string, string>, detail: string|null}
     */
    private function diskCheck(Server $server, string $bytes): array
    {
        $expected = ['size' => self::size($server->disk_mib)];

        if (! ctype_digit($bytes) || (int) $bytes === 0) {
            return ['key' => 'disk', 'status' => 'skipped', 'params' => $expected, 'detail' => 'The server did not report the size of its root file system.'];
        }

        $mib = intdiv((int) $bytes, 1024 * 1024);

        return $mib >= $server->disk_mib * self::DISK_TOLERANCE
            ? ['key' => 'disk', 'status' => 'passed', 'params' => $expected, 'detail' => null]
            : ['key' => 'disk', 'status' => 'failed', 'params' => [...$expected, 'actual' => self::size($mib)], 'detail' => 'The server reports :actual.'];
    }

    /**
     * "40 GiB", "1.5 GiB", "512 MiB".
     */
    private static function size(int $mib): string
    {
        return $mib < 1024 ? "{$mib} MiB" : rtrim(rtrim(number_format($mib / 1024, 1, '.', ''), '0'), '.').' GiB';
    }
}
