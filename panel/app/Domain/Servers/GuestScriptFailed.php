<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Proxmox\Exceptions\ProxmoxException;

/**
 * A guest script (docs/adr/0010) that must succeed did not: its message (the
 * script's first error line) is shown to administrators on the task.
 */
final class GuestScriptFailed extends ProxmoxException {}
