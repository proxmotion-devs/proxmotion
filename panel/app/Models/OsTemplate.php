<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Infrastructure\Distributions;
use App\Enums\CloneMode;
use App\Enums\OsFamily;
use App\Enums\OsPlatform;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\OsTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An operating system offered to customers, backed by one Proxmox VM template per cluster
 * (or per node, when templates live on local storage).
 *
 * It is of one version of an operating system from the administrators' list
 * (docs/adr/0026). `distribution` (the system's slug), `version` and the
 * platform are copied from it on save, for API v1 and the guest scripts;
 * `family` is only reported in API v1 (`os_family`).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $operating_system_version_id
 * @property string $name
 * @property string $distribution
 * @property string $version
 * @property OsFamily $family
 * @property OsPlatform $platform
 * @property bool $is_active
 * @property CloneMode $clone_mode linked clones when the storage allows them (docs/adr/0027)
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OperatingSystemVersion|null $osVersion
 */
#[Fillable(['name', 'operating_system_version_id', 'distribution', 'version', 'is_active', 'clone_mode', 'sort_order'])]
class OsTemplate extends Model
{
    /** @use HasFactory<OsTemplateFactory> */
    use HasFactory, HasPublicUuid;

    protected static function booted(): void
    {
        static::saving(function (OsTemplate $template) {
            // Given a system slug and version only (factories, older code): they are added to the list.
            if ($template->operating_system_version_id === null
                || ($template->isDirty(['distribution', 'version']) && ! $template->isDirty('operating_system_version_id'))) {
                $template->operating_system_version_id = self::listed($template->distribution, $template->version)->id;
            }

            $version = OperatingSystemVersion::query()->with('operatingSystem')->findOrFail($template->operating_system_version_id);
            $template->distribution = $version->operatingSystem->slug;
            $template->version = $version->version;
            $template->platform = $version->operatingSystem->platform;
            $template->family = $template->platform === OsPlatform::Windows
                ? OsFamily::Windows
                : (Distributions::family($template->distribution) ?? OsFamily::Other);
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
            'clone_mode' => CloneMode::class,
            'sort_order' => 'integer',
        ];
    }

    /** The listed version of a system by its slug, added when missing. */
    private static function listed(string $slug, string $version): OperatingSystemVersion
    {
        $system = OperatingSystem::query()->where('slug', $slug)->first() ?? OperatingSystem::query()->forceCreate([
            'slug' => $slug,
            'name' => Distributions::name($slug),
            'platform' => Distributions::family($slug) === OsFamily::Windows ? OsPlatform::Windows : OsPlatform::Linux,
        ]);

        return $system->versions()->firstOrCreate(['version' => $version]);
    }

    /**
     * @return BelongsTo<OperatingSystemVersion, $this>
     */
    public function osVersion(): BelongsTo
    {
        return $this->belongsTo(OperatingSystemVersion::class, 'operating_system_version_id');
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
     * Administrators' own scripts, run by hand or at events (docs/adr/0043).
     *
     * @return HasMany<CustomScript, $this>
     */
    public function customScripts(): HasMany
    {
        return $this->hasMany(CustomScript::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The packages that name this template among the ones they allow (docs/adr/0040).
     *
     * @return BelongsToMany<Package, $this>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class);
    }

    /**
     * The templates a package's servers may use: the ones it names, or every
     * template when it names none (docs/adr/0040).
     *
     * @param  Builder<OsTemplate>  $query
     * @return Builder<OsTemplate>
     */
    public function scopeAllowedBy(Builder $query, Package $package): Builder
    {
        return $query->when(
            $package->osTemplates()->exists(),
            fn (Builder $query) => $query->whereHas('packages', fn (Builder $query) => $query->whereKey($package->id)),
        );
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
