<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Models\OsTemplate;

/**
 * Keeps OS templates in step when administrators change a distribution or
 * its releases (docs/adr/0025): the family and the system apps are matched
 * as follow the catalog, like when a template is saved.
 */
final class DistributionChanges
{
    public static function applyTo(string $distribution): void
    {
        OsTemplate::query()->where('distribution', $distribution)->each(function (OsTemplate $template) use ($distribution) {
            $base = Distributions::base($distribution);
            $template->compatible_with = $base;
            // A version no release covers any more keeps what it was matched as.
            $template->compatible_version = $base === null
                ? null
                : (Distributions::baseVersion($distribution, $template->version) ?? $template->compatible_version);
            $template->save();
        });
    }

    /** Whether a distribution can be deleted: no OS template or other distribution refers to it. */
    public static function inUse(string $distribution): bool
    {
        return OsTemplate::query()->where('distribution', $distribution)->orWhere('compatible_with', $distribution)->exists()
            || in_array($distribution, array_column(Distributions::forForm(), 'base'), true);
    }
}
