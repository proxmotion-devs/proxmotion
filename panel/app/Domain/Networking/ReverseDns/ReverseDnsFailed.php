<?php

declare(strict_types=1);

namespace App\Domain\Networking\ReverseDns;

use RuntimeException;

/**
 * A DNS provider refused or could not be reached. Messages never contain credentials.
 */
class ReverseDnsFailed extends RuntimeException {}
