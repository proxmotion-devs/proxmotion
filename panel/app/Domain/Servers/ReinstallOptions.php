<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Models\OsTemplate;
use App\Models\Server;

/**
 * What the reinstall dialog offers: the OS templates the server can use.
 */
final class ReinstallOptions
{
    /**
     * @return array{osTemplates: list<array{value: string, label: string}>}
     */
    public static function for(Server $server): array
    {
        return [
            'osTemplates' => array_values(app(ServerReinstaller::class)->eligibleTemplates($server)
                ->map(fn (OsTemplate $template) => ['value' => $template->uuid, 'label' => $template->name])
                ->all()),
        ];
    }
}
