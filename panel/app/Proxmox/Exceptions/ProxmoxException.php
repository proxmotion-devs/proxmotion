<?php

declare(strict_types=1);

namespace App\Proxmox\Exceptions;

use RuntimeException;

/**
 * Base class for Proxmox API errors. Messages must never contain credentials.
 */
class ProxmoxException extends RuntimeException {}
