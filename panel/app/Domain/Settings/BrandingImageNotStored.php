<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use RuntimeException;

/**
 * An uploaded logo or favicon that could not be read or stored
 * (docs/adr/0053). The message is an English key, translated where it is shown.
 */
final class BrandingImageNotStored extends RuntimeException {}
