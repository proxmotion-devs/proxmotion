<?php

declare(strict_types=1);

namespace App\Proxmox\Exceptions;

/**
 * A guest agent operation that Proxmox accepted but that could not be
 * completed (command still running after the timeout, file too large).
 */
class GuestAgentException extends ProxmoxException
{
    public static function commandTimedOut(int $pid, int $seconds): self
    {
        return new self("The guest agent command (PID {$pid}) did not finish within {$seconds} seconds.");
    }

    public static function fileTooLarge(string $path, int $bytes, int $maxBytes): self
    {
        return new self("{$path} is {$bytes} bytes; the guest agent writes at most {$maxBytes} bytes at once.");
    }

    public static function inputTooLarge(int $bytes, int $maxBytes): self
    {
        return new self("The command input is {$bytes} bytes; the guest agent accepts at most {$maxBytes} bytes.");
    }
}
