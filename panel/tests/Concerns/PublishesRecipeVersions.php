<?php

namespace Tests\Concerns;

use App\Enums\AppRecipeVersionStatus;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;

/**
 * Publishes a newer version of a synced recipe, as the editor or a package
 * would, so installations of the older one can be upgraded (docs/adr/0016 §4).
 */
trait PublishesRecipeVersions
{
    /**
     * @param  array<string, mixed>  $changes  merged into recipe.yaml; `lifecycle.upgrade` is set unless $from is null
     * @param  array<string, string>  $files  added to (or replacing) the previous version's scripts
     */
    protected function publishVersion(string $slug, string $version, ?string $from = '>=1.0.0 <9.0.0', array $changes = [], array $files = []): AppRecipeVersion
    {
        $app = AppRecipe::query()->with('latestVersion')->where('slug', $slug)->firstOrFail();
        $previous = $app->latestVersion;
        $this->assertNotNull($previous);

        $definition = array_replace_recursive($previous->definition, ['version' => $version], $changes);

        if ($from !== null) {
            $definition['lifecycle']['upgrade'] = ['script' => 'upgrade.sh', 'timeout_minutes' => 7, 'from' => $from];
            $files += ['upgrade.sh' => "#!/usr/bin/env bash\nset -euo pipefail\necho upgraded\n"];
        }

        $next = (new AppRecipeVersion)->forceFill([
            'app_recipe_id' => $app->id,
            'version' => $version,
            'format' => 2,
            'definition' => $definition,
            'files' => $files + $previous->files,
            'checksum' => hash('sha256', $slug.$version),
            'status' => AppRecipeVersionStatus::Published,
            'origin' => $previous->origin,
            'reviewed_at' => now(),
        ]);
        $next->save();

        $previous->forceFill(['status' => AppRecipeVersionStatus::Archived])->save();
        $app->forceFill(['latest_version_id' => $next->id])->save();

        return $next;
    }
}
