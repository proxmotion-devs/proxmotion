<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Apps\AppInstallations;
use App\Enums\BackupKind;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Server;
use App\Models\Storage;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Backups of a server's VM (docs/adr/0051 §4): full copies made by vzdump
 * on a storage administrators ticked *Use for backups*, at most the server's
 * backup_slots. Taking, restoring and deleting one are tasks
 * (CreateBackupPipeline, RestoreBackupPipeline, DeleteBackupPipeline); the
 * row is written once Proxmox has the backup and removed once it no longer
 * has it. Backups survive reinstalls and belong to their server only.
 */
final readonly class ServerBackups
{
    /** At most this many characters of a label. */
    public const int LABEL_LENGTH = 64;

    public function __construct(private TaskRunner $tasks) {}

    /**
     * Where the server's next backup goes: of the storages marked for
     * backups that its node reaches, the available one with the most free
     * room. Shared storages appear once per node, so the node's own rows
     * are those it reaches.
     */
    public static function storageFor(Server $server): ?Storage
    {
        return $server->node->storages()
            ->where('use_for_backups', true)
            ->where('is_available', true)
            ->get()
            ->filter(fn (Storage $storage) => $storage->supportsBackups())
            ->sortByDesc(fn (Storage $storage) => $storage->freeBytes() ?? -1)
            ->first();
    }

    /**
     * Why the server cannot take backups at all (translatedMessage() for
     * pages), or null. $storage: what storageFor() found, when the caller
     * already looked it up (false: look it up here).
     */
    public static function unavailable(Server $server, Storage|false|null $storage = false): ?BackupsUnavailable
    {
        if ($server->backup_slots === 0) {
            return BackupsUnavailable::notInPlan();
        }

        return ($storage === false ? self::storageFor($server) : $storage) === null ? BackupsUnavailable::noStorage() : null;
    }

    /**
     * Why the backup cannot be restored onto its server where it is now
     * (translatedMessage() for pages), or null: its node must reach the
     * backup's storage, which a move to another node or cluster may have
     * changed. $storages: the node's storages by name, when already loaded.
     *
     * @param  Collection<string, Storage>|null  $storages
     */
    public static function notRestorable(Backup $backup, Server $server, ?Collection $storages = null): ?BackupNotRestorable
    {
        if ($backup->cluster_id !== $server->cluster_id) {
            return new BackupNotRestorable('This backup is on another cluster than the server.');
        }

        $storage = $storages !== null
            ? $storages->get($backup->storage)
            : $server->node->storages()->where('name', $backup->storage)->first();
        $reachable = $storage !== null && ($storage->is_shared || $backup->node_id === $server->node_id);

        return $reachable ? null : new BackupNotRestorable('The server\'s node cannot reach the storage of this backup.');
    }

    /**
     * The server's backups, oldest first. The storage only for
     * administrators: billing systems have no use for it nor the volume.
     *
     * @return list<array{id: string, kind: string, label: string|null, size_bytes: int|null, os_template: string|null, created_at: string|null, restorable: bool, unrestorable_reason: string|null, storage?: string}>
     */
    public static function describe(Server $server, bool $withStorage = false): array
    {
        $storages = $server->node->storages()->get()->keyBy('name');

        return array_values($server->backups()->with('osTemplate')->orderBy('id')->get()->map(function (Backup $backup) use ($server, $withStorage, $storages) {
            $reason = self::notRestorable($backup, $server, $storages);

            return [
                ...($withStorage ? ['storage' => $backup->storage] : []),
                'id' => $backup->uuid,
                'kind' => $backup->kind->value,
                'label' => $backup->label,
                'size_bytes' => $backup->size_bytes,
                'os_template' => $backup->osTemplate?->name,
                'created_at' => $backup->created_at?->toIso8601String(),
                'restorable' => $reason === null,
                'unrestorable_reason' => $reason?->translatedMessage(),
            ];
        })->all());
    }

    /**
     * Queue a new backup to the storage chosen now (storageFor()).
     *
     * @throws ProvisioningException
     */
    public function take(Server $server, ?string $label, User|ApiToken $actor, ?string $ipAddress): Task
    {
        self::ensureActive($server);

        $storage = self::storageFor($server);
        $unavailable = self::unavailable($server, $storage);

        if ($unavailable !== null) {
            throw $unavailable;
        }

        if ($server->backups()->count() >= $server->backup_slots) {
            throw new BackupSlotsFull('This server already has :count backups, as many as it may have. Delete one first.', ['count' => (string) $server->backup_slots]);
        }

        // Not null: unavailable() said so.
        $storage ??= throw BackupsUnavailable::noStorage();
        $label = $label === null || trim($label) === '' ? null : trim($label);
        $task = $this->tasks->start($server, TaskType::CreateBackup, $actor, input: [
            // The backup's uuid, also in its notes on the storage, so the volume can be told apart.
            'backup' => (string) Str::uuid(),
            'storage' => $storage->name,
            'storage_type' => $storage->type,
            'kind' => BackupKind::Manual->value,
            'label' => $label,
        ]);

        AuditLog::record('server.backup_requested', $actor, $server, ['hostname' => $server->hostname, 'storage' => $storage->name, 'label' => $label], $ipAddress);

        return $task;
    }

    /**
     * Queue a restore: the VM is replaced by the backup, then the panel
     * writes its own state back (docs/adr/0051 §5). Not while an app script
     * runs: the restore would take its disk away under it, and it runs
     * outside the server's tasks.
     *
     * @throws ProvisioningException
     */
    public function restore(Server $server, Backup $backup, User|ApiToken $actor, ?string $ipAddress): Task
    {
        self::ensureActive($server);

        $notRestorable = self::notRestorable($backup, $server);

        if ($notRestorable !== null) {
            throw $notRestorable;
        }

        if (AppInstallations::scriptRunning($server)) {
            throw new ProvisioningException('An app script is running on this server: wait until it has finished, or cancel it.');
        }

        $task = $this->tasks->start($server, TaskType::RestoreBackup, $actor, input: ['backup' => $backup->uuid]);

        AuditLog::record('server.backup_restore_requested', $actor, $server, ['hostname' => $server->hostname, 'backup' => $backup->uuid, 'volume' => $backup->volume, 'label' => $backup->label], $ipAddress);

        return $task;
    }

    /**
     * Queue deleting a backup; also while the server is suspended, to free its storage.
     *
     * @throws ProvisioningException
     */
    public function delete(Server $server, Backup $backup, User|ApiToken $actor, ?string $ipAddress): Task
    {
        if (! in_array($server->status, [ServerStatus::Active, ServerStatus::Suspended], true)) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }

        $task = $this->tasks->start($server, TaskType::DeleteBackup, $actor, input: ['backup' => $backup->uuid]);

        AuditLog::record('server.backup_deleted', $actor, $server, ['hostname' => $server->hostname, 'backup' => $backup->uuid, 'volume' => $backup->volume, 'label' => $backup->label], $ipAddress);

        return $task;
    }

    /**
     * @throws ProvisioningException
     */
    private static function ensureActive(Server $server): void
    {
        if ($server->status !== ServerStatus::Active) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }
    }
}
