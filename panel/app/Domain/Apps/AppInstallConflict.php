<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Provisioning\Exceptions\ProvisioningException;

/**
 * Another app is still being installed on the server, or this one is already
 * installed. The API answers 409.
 */
final class AppInstallConflict extends ProvisioningException {}
