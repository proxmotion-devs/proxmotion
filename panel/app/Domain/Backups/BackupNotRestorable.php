<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Provisioning\Exceptions\ProvisioningException;

/**
 * The server's node cannot reach the backup's storage, e.g. after a move
 * (docs/adr/0051 §4). The API answers 409 backup_not_restorable.
 */
final class BackupNotRestorable extends ProvisioningException {}
