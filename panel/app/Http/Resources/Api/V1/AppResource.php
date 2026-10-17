<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Apps\RecipeInput;
use App\Domain\Apps\RecipeOutput;
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
            // Format 2 recipes ask for template capabilities (requires); `os` then lists the families with any version.
            'os' => $recipe->format === 1 ? $recipe->os : array_map(fn (string $family) => ['family' => $family, 'versions' => []], $recipe->families ?? []),
            'requires' => [
                'platform' => $recipe->platform,
                'capabilities' => $recipe->capabilities,
                'families' => $recipe->families,
                'min_versions' => (object) $recipe->minVersions,
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
        ];
    }
}
