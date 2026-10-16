<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;
use App\Support\Ssh\PublicKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reinstalling wipes the server's disks and builds it again from an OS
 * template, keeping its VMID, IP addresses and resources.
 */
final readonly class ServerReinstaller
{
    public function __construct(private TaskRunner $tasks) {}

    /**
     * Active OS templates the server can be rebuilt from: those with a Proxmox
     * template on the server's cluster.
     *
     * @return Collection<int, OsTemplate>
     */
    public function eligibleTemplates(Server $server): Collection
    {
        return OsTemplate::query()
            ->where('is_active', true)
            ->whereHas('proxmoxTemplates', fn ($query) => $query->where('cluster_id', $server->cluster_id))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Record the new system (OS, a fresh root password, the given SSH
     * keys) and queue the reinstall. Nothing changes if another task runs.
     *
     * @param  list<PublicKey>  $sshKeys
     *
     * @throws ProvisioningException when another task is still running for the server
     */
    public function request(Server $server, OsTemplate $osTemplate, array $sshKeys, User|ApiToken $actor, ?string $ipAddress): Task
    {
        return DB::transaction(function () use ($server, $osTemplate, $sshKeys, $actor, $ipAddress) {
            $server->forceFill([
                'os_template_id' => $osTemplate->id,
                'root_password' => Str::password(20, symbols: false),
                'root_password_unrevealed' => true,
                'ssh_keys' => PublicKey::implode($sshKeys),
            ])->save();

            $task = $this->tasks->start($server, TaskType::Reinstall, $actor);

            AuditLog::record('server.reinstall', $actor, $server, [
                'hostname' => $server->hostname,
                'os_template' => $osTemplate->name,
                'ssh_keys' => array_map(fn (PublicKey $key) => $key->fingerprint(), $sshKeys),
            ], $ipAddress);

            return $task;
        });
    }
}
