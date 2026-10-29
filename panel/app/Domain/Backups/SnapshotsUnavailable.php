<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Provisioning\Exceptions\ProvisioningException;

/**
 * The server cannot have snapshots at all: none in its plan, or its storage
 * does not take them (docs/adr/0051 §3). The API answers 422
 * snapshots_not_supported.
 */
final class SnapshotsUnavailable extends ProvisioningException {}
