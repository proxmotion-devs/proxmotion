<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Domain\Infrastructure\Distributions;
use App\Domain\Infrastructure\TemplateNameGuess;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\OperatingSystemVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The operating system versions an app is offered on (docs/adr/0026):
 * administrators tick them; the recipe's `requires.os` only suggests some.
 */
final class AppSystems
{
    /**
     * Every listed version, with its system, in the order of the list.
     *
     * @return Collection<int, OperatingSystemVersion>
     */
    public static function all(): Collection
    {
        // Systems as administrators ordered them, newest version first.
        return OperatingSystemVersion::query()
            ->with('operatingSystem')
            ->get()
            ->sort(fn (OperatingSystemVersion $a, OperatingSystemVersion $b) => [$a->operatingSystem->sort_order, $a->operatingSystem->name] <=> [$b->operatingSystem->sort_order, $b->operatingSystem->name]
                ?: version_compare($b->version, $a->version))
            ->values();
    }

    /**
     * The versions the recipe (the published one, else the draft) suggests.
     *
     * @param  Collection<int, OperatingSystemVersion>  $versions
     * @return list<int>
     */
    public static function suggested(AppRecipe $app, Collection $versions): array
    {
        $version = $app->latestVersion ?? $app->versions()->latest('id')->first();
        $recipe = $version?->recipe();

        if ($recipe === null) {
            return [];
        }

        return array_values($versions
            ->filter(fn (OperatingSystemVersion $version) => $recipe->suggests(
                $version->operatingSystem->platform->value,
                self::osReleaseId($version->operatingSystem->slug, $version->operatingSystem->name),
                $version->version,
            ))
            ->map(fn (OperatingSystemVersion $version) => $version->id)
            ->all());
    }

    /**
     * The os-release ID a recipe would name the system by: its slug when the
     * panel knows it, else guessed from its name ("Rocky Linux" → rocky).
     */
    public static function osReleaseId(string $slug, string $name): string
    {
        if (Distributions::known($slug)) {
            return $slug;
        }

        $guess = TemplateNameGuess::fromName($name)['distribution'];

        return $guess === '' ? $slug : $guess;
    }

    /**
     * @param  list<int>  $versionIds
     */
    public static function set(AppRecipe $app, array $versionIds, User $admin, ?string $ip): void
    {
        $before = $app->osVersions()->pluck('operating_system_versions.id')->all();
        $app->osVersions()->sync($versionIds);
        $after = $app->osVersions()->pluck('operating_system_versions.id')->all();

        if (array_diff($before, $after) !== [] || array_diff($after, $before) !== []) {
            AuditLog::record('app.systems_changed', $admin, $app, [
                'app' => $app->slug,
                'added' => self::labels(array_values(array_diff($after, $before))),
                'removed' => self::labels(array_values(array_diff($before, $after))),
            ], $ip);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private static function labels(array $ids): array
    {
        return array_values(OperatingSystemVersion::query()->with('operatingSystem')->whereKey($ids)->get()
            ->map(fn (OperatingSystemVersion $version) => $version->label())
            ->all());
    }
}
