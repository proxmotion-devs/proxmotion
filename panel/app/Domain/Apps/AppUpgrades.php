<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\AppInstallation;
use App\Models\AppRecipeVersion;

/**
 * Which version an installation can be upgraded to (docs/adr/0016 §1, §4):
 * the app's published version, when it is newer than the installed one, has
 * an upgrade script whose `from` range contains the installed version, and
 * still fits the server (operating system, resources, network model). The
 * install mode and a paid app's entitlement are not checked again: the app
 * is already installed.
 */
final readonly class AppUpgrades
{
    public function __construct(private AppEligibility $eligibility) {}

    /**
     * The version to upgrade to, or null when there is none for this installation.
     */
    public function target(AppInstallation $installation): ?AppRecipeVersion
    {
        $target = $installation->recipe->latestVersion;
        $installed = $installation->version;

        if ($target === null || $target->is($installed) || ! version_compare($target->version, $installed->version, '>')) {
            return null;
        }

        $range = VersionRange::parse($target->recipe()->lifecycle['upgrade']['from'] ?? '');

        if ($range === null || ! $range->contains($installed->version)) {
            return null;
        }

        return $this->eligibility->forUpgrade($installation->recipe, $target, $installation->server) === null ? $target : null;
    }

    /**
     * The upgrade to offer for the installation now (API and admin panel), or null.
     *
     * @return array{version: string, inputs: list<array<string, mixed>>}|null
     */
    public function offer(AppInstallation $installation): ?array
    {
        $target = $installation->status->canUpgrade() ? $this->target($installation) : null;

        return $target === null ? null : [
            'version' => $target->version,
            'inputs' => array_map(fn (RecipeInput $input) => $input->toArray(), self::newInputs($installation, $target)),
        ];
    }

    /**
     * Inputs of the target version the installation has no value for: the
     * upgrade asks for them (those with a default are filled in).
     *
     * @return list<RecipeInput>
     */
    public static function newInputs(AppInstallation $installation, AppRecipeVersion $target): array
    {
        return array_values(array_filter(
            $target->recipe()->inputs,
            fn (RecipeInput $input) => ! array_key_exists($input->key, $installation->inputs),
        ));
    }
}
