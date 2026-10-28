<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use RuntimeException;

/**
 * An app package (docs/adr/0042) that the current state does not allow to be
 * installed or removed. The message is shown to the administrator as it is, so it
 * is an English key (translated in lang/tr.json).
 */
final class PackageRefused extends RuntimeException {}
