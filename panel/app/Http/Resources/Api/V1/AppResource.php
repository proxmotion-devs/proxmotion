<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Apps\RecipeAction;
use App\Domain\Apps\RecipeInput;
use App\Domain\Apps\RecipeOutput;
use App\Domain\Infrastructure\Distributions;
use App\Models\AppRecipe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An app of the marketplace (docs/adr/0015) with what a billing module needs
 * to show an install form: its inputs and the outputs it will produce.
 *
 * @mixin AppRecipe
 */
class AppResource extends JsonResource
{
    /** Whether the server the catalog was asked for may install this paid app (docs/adr/0019); null without a server. */
    private ?bool $entitled = null;

    public function withEntitlement(?bool $entitled): static
    {
        $this->entitled = $entitled;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $recipe = $this->recipe();

        return [
            'id' => $this->slug,
            'name' => $recipe->name,
            'version' => $recipe->version,
            'category' => $recipe->category,
            'description' => $recipe->description,
            'format' => $recipe->format,
            // The operating systems administrators offer the app on (docs/adr/0026), by system slug with their versions.
            'os' => $this->offeredOn(),
            'requires' => [
                'platform' => $recipe->platform,
                // Deprecated: families and distributions in one list, as before.
                'os' => $recipe->families === null && $recipe->distributions === null ? null : [...($recipe->families ?? []), ...($recipe->distributions ?? [])],
                'os_families' => $recipe->families,
                'distributions' => $recipe->distributions,
                'min_versions' => (object) $recipe->minVersions,
                'max_versions' => (object) $recipe->maxVersions,
            ],
            'requirements' => [
                'min_cores' => $recipe->minCores,
                'min_memory_mib' => $recipe->minMemoryMib,
                'min_disk_mib' => $recipe->minDiskMib,
            ],
            'network_models' => $recipe->networkModels,
            'install_modes' => $recipe->installModes,
            'billing' => $recipe->billing,
            'license' => $recipe->license,
            'inputs' => array_map(fn (RecipeInput $input) => $input->toArray(), $recipe->inputs),
            'outputs' => array_map(fn (RecipeOutput $output) => $output->toArray(), $recipe->outputs),
            // docs/adr/0016 §4: what installed copies offer.
            'can_uninstall' => $recipe->canUninstall(),
            'actions' => array_map(fn (RecipeAction $action) => $action->toArray(), $recipe->actions),
            'entitled' => $this->when($this->entitled !== null, fn () => $this->entitled),
        ];
    }

    /**
     * The ticked versions grouped by system, as v1's `os` always was: `family`
     * is the system's slug (the templates' `distribution`).
     *
     * @return list<array{family: string, versions: list<string>}>
     */
    private function offeredOn(): array
    {
        $systems = [];

        foreach ($this->osVersions->loadMissing('operatingSystem') as $version) {
            $slug = Distributions::apiName($version->operatingSystem->slug);
            $systems[$slug][] = $version->version;
        }

        return array_map(fn (string $slug, array $versions) => ['family' => $slug, 'versions' => $versions], array_keys($systems), array_values($systems));
    }
}
