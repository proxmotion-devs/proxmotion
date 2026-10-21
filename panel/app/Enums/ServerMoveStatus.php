<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * queued → running → succeeded | failed (docs/adr/0037).
 */
enum ServerMoveStatus: string
{
    case Queued = 'queued';

    case Running = 'running';

    case Succeeded = 'succeeded';

    /** Before Proxmox moved the VM, the server is where it was; after, a later move task finishes it. */
    case Failed = 'failed';

    public function isBusy(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
