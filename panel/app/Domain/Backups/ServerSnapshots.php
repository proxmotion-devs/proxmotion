<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Snapshot;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;

/**
 * Snapshots of a server's VM (docs/adr/0051 §3): disk and configuration, no
 * RAM, at most the server's snapshot_slots. Taking, rolling back and
 * deleting one are tasks (SnapshotPipeline); the row is written once
 * Proxmox has the snapshot and removed once it no longer has it.
 */
final readonly class ServerSnapshots
{
    /**
     * Storage types Proxmox takes snapshots of VM disks on; on the file
     * storages only of qcow2 images, which Proxmox itself checks.
     */
    public const array STORAGE_TYPES = ['lvmthin', 'zfspool', 'rbd', 'dir', 'nfs', 'cifs'];

    /** At most this many characters of a label. */
    public const int LABEL_LENGTH = 64;

    public function __construct(private TaskRunner $tasks) {}

    /**
     * Why the server cannot take snapshots at all (translatedMessage() for pages), or null.
     */
    public static function unavailable(Server $server): ?SnapshotsUnavailable
    {
        if ($server->snapshot_slots === 0) {
            return new SnapshotsUnavailable('Snapshots are not part of this server\'s plan.');
        }

        $type = $server->storage?->type;

        if ($type !== null && ! in_array($type, self::STORAGE_TYPES, true)) {
            return new SnapshotsUnavailable('The storage of this server (:type) does not support snapshots.', ['type' => $type]);
        }

        return null;
    }

    /**
     * The server's snapshots, oldest first.
     *
     * @return list<array{id: string, label: string|null, created_at: string|null}>
     */
    public static function describe(Server $server): array
    {
        return array_values($server->snapshots()->orderBy('id')->get()->map(fn (Snapshot $snapshot) => [
            'id' => $snapshot->uuid,
            'label' => $snapshot->label,
            'created_at' => $snapshot->created_at?->toIso8601String(),
        ])->all());
    }

    /**
     * Queue a new snapshot.
     *
     * @throws ProvisioningException
     */
    public function take(Server $server, ?string $label, User|ApiToken $actor, ?string $ipAddress): Task
    {
        self::ensureActive($server);

        $unavailable = self::unavailable($server);

        if ($unavailable !== null) {
            throw $unavailable;
        }

        if ($server->snapshots()->count() >= $server->snapshot_slots) {
            throw new SnapshotSlotsFull('This server already has :count snapshots, as many as it may have. Delete one first.', ['count' => (string) $server->snapshot_slots]);
        }

        // Proxmox's rules: a letter first, then letters, digits, "-" and "_", at most 40 characters.
        $name = 'pmx'.base_convert((string) now()->getTimestampMs(), 10, 36);
        $label = $label === null || trim($label) === '' ? null : trim($label);
        $task = $this->tasks->start($server, TaskType::CreateSnapshot, $actor, input: ['name' => $name, 'label' => $label]);

        AuditLog::record('server.snapshot_requested', $actor, $server, ['hostname' => $server->hostname, 'snapshot' => $name, 'label' => $label], $ipAddress);

        return $task;
    }

    /**
     * Queue a rollback: the server's disk and configuration go back to the
     * snapshot, then the panel writes its own state back (docs/adr/0051 §5).
     *
     * @throws ProvisioningException
     */
    public function rollback(Server $server, Snapshot $snapshot, User|ApiToken $actor, ?string $ipAddress): Task
    {
        self::ensureActive($server);
        $task = $this->tasks->start($server, TaskType::RollbackSnapshot, $actor, input: ['snapshot' => $snapshot->uuid]);

        AuditLog::record('server.snapshot_rolled_back', $actor, $server, ['hostname' => $server->hostname, 'snapshot' => $snapshot->name, 'label' => $snapshot->label], $ipAddress);

        return $task;
    }

    /**
     * Queue deleting a snapshot; also while the server is suspended, to free its storage.
     *
     * @throws ProvisioningException
     */
    public function delete(Server $server, Snapshot $snapshot, User|ApiToken $actor, ?string $ipAddress): Task
    {
        if (! in_array($server->status, [ServerStatus::Active, ServerStatus::Suspended], true)) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }

        $task = $this->tasks->start($server, TaskType::DeleteSnapshot, $actor, input: ['snapshot' => $snapshot->uuid]);

        AuditLog::record('server.snapshot_deleted', $actor, $server, ['hostname' => $server->hostname, 'snapshot' => $snapshot->name, 'label' => $snapshot->label], $ipAddress);

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
