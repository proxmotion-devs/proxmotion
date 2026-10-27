<?php

declare(strict_types=1);

namespace App\Proxmox\Resources;

use App\Proxmox\Data\GuestExecStatus;
use App\Proxmox\Data\GuestFile;
use App\Proxmox\Data\GuestFileSystem;
use App\Proxmox\Data\GuestNetworkInterface;
use App\Proxmox\Exceptions\GuestAgentException;
use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\ProxmoxClient;
use Illuminate\Support\Sleep;

/**
 * The QEMU guest agent of VMs on one node, through the Proxmox API.
 *
 * Proxmox VE 9 checks VM.GuestAgent.* privileges (exec and set-user-password
 * need VM.GuestAgent.Unrestricted); older releases checked VM.Monitor.
 * Requests fail with a ProxmoxRequestFailed (HTTP 500) when the VM is not
 * running or its agent does not answer, and with a GuestAgentException when
 * the guest's qemu-ga settings block the command.
 */
final readonly class GuestAgentResource
{
    /** file-write accepts at most 61440 characters of (base64) content. */
    public const MAX_WRITE_BYTES = 46080;

    /** exec accepts at most 65536 characters of input-data. */
    public const MAX_INPUT_BYTES = 65536;

    public function __construct(
        private ProxmoxClient $client,
        private string $node,
    ) {}

    /**
     * Whether the agent answers. Never throws for an agent that is down or a
     * stopped VM; a cluster that cannot be reached still throws.
     */
    public function ping(int $vmid): bool
    {
        try {
            $this->client->post($this->path($vmid, 'ping'));
        } catch (ProxmoxRequestFailed) {
            return false;
        }

        return true;
    }

    /**
     * Create or overwrite a file. The content is base64-encoded here so binary
     * data survives the trip.
     *
     * @throws GuestAgentException when the content is larger than one write allows
     */
    public function writeFile(int $vmid, string $file, string $content): void
    {
        if (strlen($content) > self::MAX_WRITE_BYTES) {
            throw GuestAgentException::fileTooLarge($file, strlen($content), self::MAX_WRITE_BYTES);
        }

        $this->post($vmid, 'file-write', [
            'file' => $file,
            'content' => base64_encode($content),
            'encode' => 0,
        ]);
    }

    /**
     * Read a file (up to 16 MiB; check GuestFile::$truncated).
     */
    public function readFile(int $vmid, string $file): GuestFile
    {
        $data = $this->get($vmid, 'file-read', ['file' => $file]);

        return GuestFile::fromApi(is_array($data) ? $data : []);
    }

    /**
     * Start a program in the guest and return its PID. The command is a list of
     * program and arguments; it is never run through a shell by the agent.
     *
     * @param  non-empty-list<string>  $command
     *
     * @throws GuestAgentException when the input is too large
     */
    public function exec(int $vmid, array $command, ?string $input = null): int
    {
        $params = ['command' => $command];

        if ($input !== null) {
            if (strlen($input) > self::MAX_INPUT_BYTES) {
                throw GuestAgentException::inputTooLarge(strlen($input), self::MAX_INPUT_BYTES);
            }

            $params['input-data'] = $input;
        }

        $data = $this->post($vmid, 'exec', $params);

        return is_array($data) && is_numeric($data['pid'] ?? null) ? (int) $data['pid'] : 0;
    }

    public function execStatus(int $vmid, int $pid): GuestExecStatus
    {
        $data = $this->get($vmid, 'exec-status', ['pid' => $pid]);

        return GuestExecStatus::fromApi(is_array($data) ? $data : []);
    }

    /**
     * Run a short command and wait for it to exit. Long-running work (app
     * installs) is started detached instead and tracked through a status file.
     *
     * @param  non-empty-list<string>  $command
     *
     * @throws GuestAgentException when the command is still running after the timeout
     */
    public function run(int $vmid, array $command, ?string $input = null, int $timeoutSeconds = 60, int $pollSeconds = 1): GuestExecStatus
    {
        $pid = $this->exec($vmid, $command, $input);
        $deadline = now()->addSeconds($timeoutSeconds);

        while (true) {
            $status = $this->execStatus($vmid, $pid);

            if ($status->exited) {
                return $status;
            }

            if (now()->greaterThanOrEqualTo($deadline)) {
                throw GuestAgentException::commandTimedOut($pid, $timeoutSeconds);
            }

            Sleep::for($pollSeconds)->seconds();
        }
    }

    /**
     * run() for a command the next steps depend on (creating directories):
     * a non-zero exit throws with its error output instead of letting the
     * following file writes fail without the reason.
     *
     * @param  non-empty-list<string>  $command
     *
     * @throws GuestAgentException when the command fails or times out
     */
    public function runOrFail(int $vmid, array $command, int $timeoutSeconds = 60): GuestExecStatus
    {
        $status = $this->run($vmid, $command, timeoutSeconds: $timeoutSeconds);

        if (! $status->succeeded()) {
            throw GuestAgentException::commandFailed($vmid, $command, $status->exitCode, $status->stderr);
        }

        return $status;
    }

    /**
     * The guest's network interfaces with their MAC and IP addresses, as the
     * agent reports them (guest-network-get-interfaces).
     *
     * @return list<GuestNetworkInterface>
     */
    public function networkInterfaces(int $vmid): array
    {
        $data = $this->get($vmid, 'network-get-interfaces', []);
        $interfaces = is_array($data) && is_array($data['result'] ?? null) ? $data['result'] : [];

        return array_values(array_map(
            fn (array $interface) => GuestNetworkInterface::fromApi($interface),
            array_filter($interfaces, is_array(...)),
        ));
    }

    /**
     * The guest's mounted file systems with their sizes, as the agent reports
     * them (guest-get-fsinfo).
     *
     * @return list<GuestFileSystem>
     */
    public function fileSystems(int $vmid): array
    {
        $data = $this->get($vmid, 'get-fsinfo', []);
        $fileSystems = is_array($data) && is_array($data['result'] ?? null) ? $data['result'] : [];

        return array_values(array_map(
            fn (array $fileSystem) => GuestFileSystem::fromApi($fileSystem),
            array_filter($fileSystems, is_array(...)),
        ));
    }

    /**
     * Set a user's password inside the guest. The password is sent to Proxmox
     * only; callers must not log it.
     */
    public function setUserPassword(int $vmid, string $username, string $password): void
    {
        $this->post($vmid, 'set-user-password', [
            'username' => $username,
            'password' => $password,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function post(int $vmid, string $command, array $data): mixed
    {
        try {
            return $this->client->post($this->path($vmid, $command), $data);
        } catch (ProxmoxRequestFailed $e) {
            throw self::disabled($vmid, $e) ?? $e;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(int $vmid, string $command, array $query): mixed
    {
        try {
            return $this->client->get($this->path($vmid, $command), $query);
        } catch (ProxmoxRequestFailed $e) {
            throw self::disabled($vmid, $e) ?? $e;
        }
    }

    /**
     * The agent answers "Command guest-exec has been disabled" for commands its
     * settings in the guest block.
     */
    private static function disabled(int $vmid, ProxmoxRequestFailed $e): ?GuestAgentException
    {
        return preg_match('/Command (guest-[a-z-]+) has been disabled/', $e->getMessage(), $match) === 1
            ? GuestAgentException::commandDisabled($vmid, $match[1])
            : null;
    }

    private function path(int $vmid, string $command): string
    {
        return '/nodes/'.rawurlencode($this->node).'/qemu/'.$vmid.'/agent/'.$command;
    }
}
