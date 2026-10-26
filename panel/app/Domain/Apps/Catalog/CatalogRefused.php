<?php

declare(strict_types=1);

namespace App\Domain\Apps\Catalog;

use RuntimeException;

/**
 * An App Store action the current state does not allow. The message is shown
 * to the administrator as it is, so it is an English key (translated in lang/tr.json).
 */
final class CatalogRefused extends RuntimeException {}
