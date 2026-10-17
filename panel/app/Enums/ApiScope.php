<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an API token may do (docs/adr/0008).
 */
enum ApiScope: string
{
    /** Packages, locations and OS templates. */
    case CatalogRead = 'catalog:read';

    /** Servers, their addresses and tasks. */
    case ServersRead = 'servers:read';

    /** Create, suspend, unsuspend, terminate, power, reinstall, password reset, rDNS. */
    case ServersWrite = 'servers:write';

    /** New root passwords (once) and console links. */
    case ServersSecrets = 'servers:secrets';

    /** Outputs of installed apps: VPN profiles, generated passwords (docs/adr/0015). */
    case AppsSecrets = 'apps:secrets';

    /**
     * Admin form label (English key).
     */
    public function label(): string
    {
        return match ($this) {
            self::CatalogRead => 'Read packages, locations and OS templates',
            self::ServersRead => 'Read servers and tasks',
            self::ServersWrite => 'Create and manage servers',
            self::ServersSecrets => 'Fetch root passwords and console links',
            self::AppsSecrets => 'Fetch the outputs of installed apps',
        };
    }
}
