<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupKind;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A backup the panel took of a server's VM with vzdump (docs/adr/0051 §4).
 * Proxmox holds it on a storage ticked *Use for backups*. Written only by
 * the backup pipelines once Proxmox has the file, never mass-assigned.
 *
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int $cluster_id
 * @property int|null $node_id the node it was taken on; with a storage that is not shared, the only node that reaches it
 * @property string $storage the storage's name
 * @property string $volume Proxmox's volume id
 * @property int $vmid the VMID it was taken of
 * @property BackupKind $kind
 * @property string|null $label the customer's or administrator's text
 * @property int|null $size_bytes
 * @property int|null $os_template_id the server's OS template when it was taken
 * @property string $hostname the server's hostname when it was taken
 * @property int $disk_mib the server's disk size when it was taken
 * @property int|null $task_id the task that took it
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read Cluster $cluster
 * @property-read Node|null $node
 * @property-read OsTemplate|null $osTemplate
 * @property-read Task|null $task
 */
class Backup extends Model
{
    use HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BackupKind::class,
            'size_bytes' => 'integer',
            'vmid' => 'integer',
            'disk_mib' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Cluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return BelongsTo<OsTemplate, $this>
     */
    public function osTemplate(): BelongsTo
    {
        return $this->belongsTo(OsTemplate::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
