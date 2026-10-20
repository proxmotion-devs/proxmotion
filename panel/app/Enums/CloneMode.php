<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the servers of an OS template are cloned from its Proxmox template (docs/adr/0027).
 */
enum CloneMode: string
{
    /** A full copy of every disk: slower, independent of the template. */
    case Full = 'full';

    /** A linked clone sharing the template's base disk, when the storage allows it; otherwise full. */
    case Linked = 'linked';
}
