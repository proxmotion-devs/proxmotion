<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an app run does to an installation (docs/adr/0016 §4).
 */
enum AppRunKind: string
{
    case Uninstall = 'uninstall';
    case Action = 'action';

    /** Moves the installation to a newer version of its recipe with the new version's upgrade script. */
    case Upgrade = 'upgrade';
}
