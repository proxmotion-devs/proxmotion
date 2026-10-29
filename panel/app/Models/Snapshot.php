<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A snapshot the panel took of a server's VM (docs/adr/0051 §3): disk and
 * configuration, no RAM. Proxmox holds it on the VM's storage. Written only
 * by SnapshotPipeline, never mass-assigned.
 *
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property string $name Proxmox's snapname
 * @property string|null $label the customer's or administrator's text
 * @property int|null $task_id the task that took it
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read Task|null $task
 */
class Snapshot extends Model
{
    use HasPublicUuid;

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
