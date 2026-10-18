<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppInstallMode;
use App\Models\AppRecipe;
use App\Models\OsTemplate;
use App\Models\Server;

/**
 * The apps the admin forms offer with a new system (docs/adr/0016 §5), each
 * with the OS templates it fits, so the form lists only those that suit
 * the chosen template. For a new server the resources and network model
 * are checked when the form is sent; for a reinstall they are the server's.
 */
final readonly class OrderedAppOptions
{
    public function __construct(private AppEligibility $eligibility) {}

    /**
     * @param  iterable<OsTemplate>  $osTemplates
     * @param  Server|null  $server  the server being reinstalled
     * @return list<array{slug: string, name: string, os_templates: list<string>, inputs: list<array<string, mixed>>}>
     */
    public function for(iterable $osTemplates, ?Server $server = null): array
    {
        $osTemplates = collect($osTemplates);
        $options = [];

        foreach (AppRecipe::query()->with('latestVersion')->where('is_active', true)->orderBy('name')->get() as $app) {
            $fits = $osTemplates->filter(fn (OsTemplate $template) => ($server === null
                ? $this->eligibility->forCatalog($app, $template, null, AppInstallMode::Order)
                : $this->eligibility->forOrder($app, $template, $server->cpu_cores, $server->memory_mib, $server->disk_mib, $server->network_model)) === null);

            if ($fits->isEmpty()) {
                continue;
            }

            $options[] = [
                'slug' => $app->slug,
                'name' => $app->name,
                'os_templates' => array_values($fits->map(fn (OsTemplate $template) => $template->uuid)->all()),
                'inputs' => array_map(fn (RecipeInput $input) => $input->toArray(), $app->recipe()->inputs),
            ];
        }

        return $options;
    }
}
