<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Enums\OsFamily;
use App\Models\Distribution;
use App\Models\DistributionRelease;

/**
 * The distributions administrators manage (docs/adr/0025), read once per
 * request; Distributions reads it. Saving or deleting a distribution or a
 * release forgets it.
 */
final class DistributionCatalog
{
    /** @var array<string, array{name: string, family: OsFamily, base: string|null, same_version: bool, sort_order: int, releases: list<array{id: int, version: string, base_version: string|null}>}>|null */
    private ?array $entries = null;

    /**
     * By os-release ID, in the order administrators see them; releases newest first.
     *
     * @return array<string, array{name: string, family: OsFamily, base: string|null, same_version: bool, sort_order: int, releases: list<array{id: int, version: string, base_version: string|null}>}>
     */
    public function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        $distributions = Distribution::query()->with('releases')->orderBy('sort_order')->orderBy('name')->get();

        foreach ($distributions as $distribution) {
            $releases = $distribution->releases
                ->map(fn (DistributionRelease $release) => ['id' => $release->id, 'version' => $release->version, 'base_version' => $release->base_version])
                ->all();
            usort($releases, fn (array $a, array $b) => self::compare($b['version'], $a['version']));

            $entries[$distribution->slug] = [
                'name' => $distribution->name,
                'family' => $distribution->family,
                'base' => $distribution->base,
                'same_version' => $distribution->same_version,
                'sort_order' => $distribution->sort_order,
                'releases' => $releases,
            ];
        }

        return $this->entries = $entries;
    }

    public function forget(): void
    {
        $this->entries = null;
    }

    /** Versions compared as numbers; "rolling" comes before every other. */
    private static function compare(string $a, string $b): int
    {
        if ($a === 'rolling' || $b === 'rolling') {
            return ($a === 'rolling' ? 1 : 0) - ($b === 'rolling' ? 1 : 0);
        }

        return version_compare($a, $b);
    }
}
