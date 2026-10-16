<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Where PTR records of IP pools are published (docs/adr/0003). Drivers:
 * "none" (record only, export by hand) and "powerdns" (PowerDNS HTTP API).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $driver
 * @property array<string, string>|null $options driver settings, e.g. api_url, server_id
 * @property array<string, string>|null $credentials encrypted, e.g. api_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'driver', 'options'])]
#[Hidden(['credentials'])]
class DnsProvider extends Model
{
    use HasPublicUuid;

    public const DRIVERS = ['none', 'powerdns'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'credentials' => 'encrypted:array',
        ];
    }

    /**
     * @return HasMany<IpPool, $this>
     */
    public function ipPools(): HasMany
    {
        return $this->hasMany(IpPool::class);
    }
}
