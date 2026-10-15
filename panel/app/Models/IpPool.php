<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\IpPoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $location_id
 * @property string $name
 * @property IpVersion $version
 * @property NetworkModel $network_model
 * @property string $network
 * @property string|null $gateway
 * @property string $bridge
 * @property int|null $vlan_tag
 * @property list<string>|null $nameservers
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location $location
 */
#[Fillable(['location_id', 'name', 'version', 'network_model', 'network', 'gateway', 'bridge', 'vlan_tag', 'nameservers', 'is_active'])]
class IpPool extends Model
{
    /** @use HasFactory<IpPoolFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => IpVersion::class,
            'network_model' => NetworkModel::class,
            'vlan_tag' => 'integer',
            'nameservers' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<IpAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }
}
