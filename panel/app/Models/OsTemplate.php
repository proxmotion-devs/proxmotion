<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OsPlatform;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\OsTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An operating system offered to customers, backed by one Proxmox VM template per cluster
 * (or per node, when templates live on local storage).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $family
 * @property string $version
 * @property OsPlatform $platform
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'family', 'version', 'platform', 'is_active', 'sort_order'])]
class OsTemplate extends Model
{
    /** @use HasFactory<OsTemplateFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => OsPlatform::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<ProxmoxTemplate, $this>
     */
    public function proxmoxTemplates(): BelongsToMany
    {
        return $this->belongsToMany(ProxmoxTemplate::class);
    }

    /**
     * Scripts replacing the defaults for some events (docs/adr/0010).
     *
     * @return HasMany<GuestScript, $this>
     */
    public function guestScripts(): HasMany
    {
        return $this->hasMany(GuestScript::class);
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
