<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Infrastructure\Distributions;
use App\Enums\OsFamily;
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
 * `distribution` and `version` say what it is, like ID and VERSION_ID of
 * /etc/os-release; the family (and with it the platform) follows from the
 * distribution when the catalog knows it (docs/adr/0024). A distribution
 * with its own version numbers names the one apps are matched as in
 * `compatible_with` / `compatible_version` (Pardus 23.4 as Debian 12).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $distribution
 * @property string $version
 * @property OsFamily $family
 * @property string|null $compatible_with
 * @property string|null $compatible_version
 * @property OsPlatform $platform
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'distribution', 'version', 'family', 'compatible_with', 'compatible_version', 'is_active', 'sort_order'])]
class OsTemplate extends Model
{
    /** @use HasFactory<OsTemplateFactory> */
    use HasFactory, HasPublicUuid;

    protected static function booted(): void
    {
        static::saving(function (OsTemplate $template) {
            $template->family = Distributions::family($template->distribution) ?? $template->family ?? OsFamily::Other;
            $template->platform = $template->family->platform();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'family' => OsFamily::class,
            'platform' => OsPlatform::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The distribution apps are matched as (docs/adr/0024 §4). */
    public function matchDistribution(): string
    {
        return $this->compatible_with ?? $this->distribution;
    }

    /** The version apps are matched as. */
    public function matchVersion(): string
    {
        return $this->compatible_with === null ? $this->version : (string) $this->compatible_version;
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
