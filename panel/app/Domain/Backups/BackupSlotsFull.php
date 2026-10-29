<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Provisioning\Exceptions\ProvisioningException;

/**
 * The server has as many backups as it may (docs/adr/0051 §2); one must be
 * deleted first. The API answers 409 backup_slots_full.
 */
final class BackupSlotsFull extends ProvisioningException {}
