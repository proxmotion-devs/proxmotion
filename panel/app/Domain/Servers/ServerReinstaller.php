<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppNotEligible;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskRunner;
use App\Support\Ssh\PublicKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Reinstalling wipes the server's disks and builds it again from an OS
 * template, keeping its VMID, IP addresses and resources.
 */
final readonly class ServerReinstaller
{
    public function __construct(
        private TaskRunner $tasks,
        private AppInstaller $apps,
        private PasswordGenerator $passwords,
        private ReinstallLimits $limits,
    ) {}

    /**
     * OS templates the server can be rebuilt from: those with a Proxmox
     * template on the server's cluster. A server of a billing system gets
     * active ones only; one without may also try an inactive template and
     * then becomes a test server (docs/adr/0024 §8). Inactive ones are last.
     *
     * @return Collection<int, OsTemplate>
     */
    public function eligibleTemplates(Server $server): Collection
    {
        $query = OsTemplate::query()
            ->when($server->billing_system_id !== null, fn ($query) => $query->where('is_active', true))
            ->whereHas('proxmoxTemplates', fn ($query) => $query->where('cluster_id', $server->cluster_id));

        // The server's package may name the templates it uses; none means any (docs/adr/0040).
        if ($server->package !== null) {
            $query->allowedBy($server->package);
        }

        return $query
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Record the new system (OS, a fresh root password, the given SSH
     * keys, optionally an app installed once it has booted) and queue the
     * reinstall. Nothing changes if another task runs, or when a billing
     * system has used up the server's reinstalls (docs/adr/0045).
     *
     * @param  list<PublicKey>  $sshKeys
     * @param  array<array-key, mixed>  $appInputs  the app's inputs, not validated yet
     *
     * @throws ProvisioningException when another task is still running for the server
     * @throws ReinstallLimitReached when an API token asks beyond the server's reinstall limit
     * @throws AppNotEligible when the app does not fit the new system
     * @throws ValidationException for invalid app inputs
     */
    public function request(Server $server, OsTemplate $osTemplate, array $sshKeys, User|ApiToken $actor, ?string $ipAddress, ?AppRecipe $app = null, #[SensitiveParameter] array $appInputs = []): Task
    {
        // Administrators are never limited (docs/adr/0045 §4).
        if ($actor instanceof ApiToken) {
            $this->limits->check($server);
        }

        $appValues = $app === null ? [] : $this->apps->validateOrdered($app, $appInputs, $osTemplate, $server->cpu_cores, $server->memory_mib, $server->disk_mib, $server->network_model, $server);

        if (! $osTemplate->is_active && $server->billing_system_id !== null) {
            throw new ProvisioningException(ServerCreator::INACTIVE_TEMPLATE);
        }

        if ($server->package !== null && ! $server->package->allowsOsTemplate($osTemplate)) {
            throw ProvisioningException::templateNotAllowed();
        }

        return DB::transaction(function () use ($server, $osTemplate, $sshKeys, $actor, $ipAddress, $app, $appValues) {
            $server->forceFill([
                'os_template_id' => $osTemplate->id,
                'root_password' => $this->passwords->generate(),
                'root_password_unrevealed' => true,
                'ssh_keys' => PublicKey::implode($sshKeys),
                // The new VM is named after the hostname, which cloud-init then sets (docs/adr/0031 §2a).
                'vm_name' => null,
                // Trying an inactive template makes it a test server, as when one is created from it.
                'is_test' => $server->is_test || ! $osTemplate->is_active,
            ])->save();

            $task = $this->tasks->start($server, TaskType::Reinstall, $actor);

            AuditLog::record('server.reinstall', $actor, $server, [
                'hostname' => $server->hostname,
                'os_template' => $osTemplate->name,
                'ssh_keys' => array_map(fn (PublicKey $key) => $key->fingerprint(), $sshKeys),
            ] + ($app === null ? [] : ['app' => $app->slug]) + ($osTemplate->is_active ? [] : ['test_server' => true]), $ipAddress);

            if ($app !== null) {
                $this->apps->queueOrdered($server, $app, $appValues, $actor, $ipAddress);
            } else {
                $this->apps->dropOrdered($server);
            }

            return $task;
        });
    }
}
