<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Provisioning\Exceptions\ProvisioningException;

/**
 * The admin recipe editor refuses a change (docs/adr/0018): a bundled app,
 * no draft, an app still in use. The message is an English translation key.
 */
final class RecipeEditRefused extends ProvisioningException {}
