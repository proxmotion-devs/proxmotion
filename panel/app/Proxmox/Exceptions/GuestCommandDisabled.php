<?php

declare(strict_types=1);

namespace App\Proxmox\Exceptions;

/**
 * The guest's qemu-ga settings block the command: asking again never helps
 * (GuestAgentException::commandDisabled()).
 */
final class GuestCommandDisabled extends GuestAgentException {}
