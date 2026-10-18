<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Domain\Apps\OrderedAppOptions;
use App\Models\OsTemplate;
use App\Models\Server;

/**
 * What the reinstall dialog offers: the OS templates the server can use,
 * and the apps that can come with the new system (docs/adr/0016 §5).
 */
final class ReinstallOptions
{
    /**
     * @return array{osTemplates: list<array{value: string, label: string}>, apps: list<array{slug: string, name: string, os_templates: list<string>, inputs: list<array<string, mixed>>}>}
     */
    public static function for(Server $server): array
    {
        $templates = app(ServerReinstaller::class)->eligibleTemplates($server);

        return [
            'osTemplates' => array_values($templates
                ->map(fn (OsTemplate $template) => ['value' => $template->uuid, 'label' => $template->name])
                ->all()),
            'apps' => app(OrderedAppOptions::class)->for($templates, $server),
        ];
    }
}
