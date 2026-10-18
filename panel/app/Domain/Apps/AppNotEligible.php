<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Provisioning\Exceptions\ProvisioningException;

/**
 * The app cannot be installed on this server or with this OS and package
 * (docs/adr/0015). The API answers 422.
 */
class AppNotEligible extends ProvisioningException {}
