<?php

namespace Tests\Concerns;

use App\Domain\Apps\AppSystems;
use App\Models\AppRecipe;

/**
 * Ticks every app for the operating system versions its recipe suggests
 * (docs/adr/0026), as an administrator would with *Tick the suggested ones*.
 */
trait OffersApps
{
    protected function offerApps(): void
    {
        $versions = AppSystems::all();

        foreach (AppRecipe::query()->get() as $app) {
            $app->osVersions()->syncWithoutDetaching(AppSystems::suggested($app, $versions));
        }
    }
}
