<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StorageHealth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A physical disk of a node, written by the cluster sync (docs/adr/0047).
 * Disks no longer reported are kept with is_present false.
 *
 * @phpstan-import-type HealthProblem from Storage
 *
 * @property int $id
 * @property int $node_id
 * @property string $devpath
 * @property string|null $model
 * @property string|null $serial
 * @property string|null $type ssd, hdd, nvme, usb…
 * @property int|null $size_bytes
 * @property string|null $used what Proxmox says uses it (LVM, ZFS, partitions…)
 * @property string|null $smart smartctl's overall result (PASSED, FAILED, OK, UNKNOWN)
 * @property int|null $wearout rated life left in per cent (SSDs and NVMe)
 * @property StorageHealth $health
 * @property list<HealthProblem>|null $health_problems
 * @property bool $is_present
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node $node
 */
class NodeDisk extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'wearout' => 'integer',
            'health' => StorageHealth::class,
            'health_problems' => 'array',
            'is_present' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
