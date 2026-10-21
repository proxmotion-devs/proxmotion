<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $code
 * @property string|null $country_code
 * @property string|null $description
 * @property string|null $hostname_template hostnames of servers ordered without one, e.g. "ip-{ip}.example.com" (docs/adr/0031)
 * @property list<string>|null $protected_networks CIDRs its servers may not open connections to (docs/adr/0034)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'code', 'country_code', 'description', 'hostname_template', 'protected_networks'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'protected_networks' => 'array',
        ];
    }

    /**
     * @return HasMany<Cluster, $this>
     */
    public function clusters(): HasMany
    {
        return $this->hasMany(Cluster::class);
    }

    /**
     * @return HasMany<PlacementGroup, $this>
     */
    public function placementGroups(): HasMany
    {
        return $this->hasMany(PlacementGroup::class);
    }

    /**
     * @return HasMany<IpPool, $this>
     */
    public function ipPools(): HasMany
    {
        return $this->hasMany(IpPool::class);
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
