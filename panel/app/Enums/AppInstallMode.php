<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * When an app is installed (docs/adr/0016 §5), named like a recipe's
 * install_modes: later on a running server, or with the system it was
 * ordered or reinstalled with (a post-boot hook, docs/adr/0004).
 */
enum AppInstallMode: string
{
    case Post = 'post';
    case Order = 'order';
}
