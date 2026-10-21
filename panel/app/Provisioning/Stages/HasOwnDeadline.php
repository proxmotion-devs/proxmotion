<?php

declare(strict_types=1);

namespace App\Provisioning\Stages;

/**
 * A staged pipeline whose tasks have a deadline of their own instead of the
 * provisioning deadline (docs/adr/0032), such as a move that copies disks
 * (docs/adr/0037).
 */
interface HasOwnDeadline
{
    public function deadlineMinutes(): int;
}
