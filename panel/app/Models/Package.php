<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NetworkModel;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A VPS plan. Units: memory and disk in MiB, traffic in bytes, port speed in Mbps.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int $cpu_cores
 * @property int $memory_mib
 * @property int $disk_mib
 * @property int|null $traffic_bytes
 * @property int|null $port_speed_mbps
 * @property int $ipv4_count
 * @property int $ipv6_count
 * @property int $snapshot_slots
 * @property int $backup_slots
 * @property Collection<int, NetworkModel> $network_models
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'cpu_cores', 'memory_mib', 'disk_mib', 'traffic_bytes', 'port_speed_mbps',
    'ipv4_count', 'ipv6_count', 'snapshot_slots', 'backup_slots', 'network_models', 'is_active', 'sort_order',
])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cpu_cores' => 'integer',
            'memory_mib' => 'integer',
            'disk_mib' => 'integer',
            'traffic_bytes' => 'integer',
            'port_speed_mbps' => 'integer',
            'ipv4_count' => 'integer',
            'ipv6_count' => 'integer',
            'snapshot_slots' => 'integer',
            'backup_slots' => 'integer',
            'network_models' => AsEnumCollection::of(NetworkModel::class),
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
