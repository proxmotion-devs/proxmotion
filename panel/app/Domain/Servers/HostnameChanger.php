<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Domain\Networking\ReverseDns\ReverseDns;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;
use Illuminate\Support\Facades\DB;

/**
 * Renames a server (docs/adr/0012). The panel's hostname changes at once,
 * PTRs equal to the old hostname follow it, and a change_hostname task
 * renames the system inside the server through the guest agent. The Proxmox
 * VM keeps its name: renaming it would make cloud-init see a new instance.
 */
final readonly class HostnameChanger
{
    public function __construct(
        private TaskRunner $tasks,
        private ReverseDns $reverseDns,
    ) {}

    /**
     * @param  string  $hostname  validated with the Hostname rule
     * @param  bool  $reboot  restart a Windows server so the new name takes effect now
     *
     * @throws ProvisioningException
     */
    public function request(Server $server, string $hostname, bool $reboot, User|ApiToken $actor, ?string $ipAddress): Task
    {
        if ($server->status !== ServerStatus::Active) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }

        $hostname = strtolower(rtrim(trim($hostname), '.'));
        $previous = $server->hostname;

        if ($hostname === $previous) {
            throw new ProvisioningException('The server is already called :hostname.', ['hostname' => $hostname]);
        }

        $task = DB::transaction(function () use ($server, $hostname, $previous, $reboot, $actor, $ipAddress) {
            $server->forceFill(['hostname' => $hostname])->save();

            // Only PTRs that simply were the server's name; chosen ones stay.
            foreach ($server->ipAddresses()->get() as $address) {
                if ($address->ptr !== null && strcasecmp(rtrim($address->ptr, '.'), $previous) === 0) {
                    $this->reverseDns->update($address, $hostname, $actor, $ipAddress);
                }
            }

            // Refuses while another task runs, which rolls the rename back.
            return $this->tasks->start($server, TaskType::ChangeHostname, $actor, input: ['previous_hostname' => $previous, 'reboot' => $reboot]);
        });

        AuditLog::record('server.hostname_changed', $actor, $server, ['from' => $previous, 'to' => $hostname], $ipAddress);

        return $task;
    }
}
