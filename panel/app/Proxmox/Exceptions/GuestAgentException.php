<?php

declare(strict_types=1);

namespace App\Proxmox\Exceptions;

/**
 * A guest agent operation that Proxmox accepted but that could not be
 * completed (command still running after the timeout, file too large).
 */
class GuestAgentException extends ProxmoxException
{
    public static function notReachable(int $vmid): self
    {
        return new self("The QEMU guest agent of VM {$vmid} does not answer. Is the VM running and qemu-guest-agent installed?");
    }

    /**
     * RHEL-family images (AlmaLinux, Rocky, CentOS, Fedora) ship qemu-ga with
     * guest-exec and the file commands blocked in /etc/sysconfig/qemu-ga.
     */
    public static function commandDisabled(int $vmid, string $command): GuestCommandDisabled
    {
        return new GuestCommandDisabled("The guest agent of VM {$vmid} refuses {$command}: it is disabled in the guest's qemu-ga settings (AlmaLinux, Rocky and RHEL block it by default). Allow it in /etc/sysconfig/qemu-ga of the template and the server, then restart qemu-guest-agent (see docs/guides/os-templates.md).");
    }

    /**
     * A preparing command (mkdir) that must succeed did not. SELinux confines
     * what the agent's commands may do on RHEL-family guests.
     *
     * @param  non-empty-list<string>  $command
     */
    public static function commandFailed(int $vmid, array $command, ?int $exitCode, string $stderr): self
    {
        $reason = trim(strtok($stderr."\n", "\n") ?: '');
        $message = "{$command[0]} failed inside VM {$vmid} (exit code ".($exitCode ?? 'unknown').')'.($reason !== '' ? ": {$reason}" : '.');

        if (str_contains($stderr, 'Permission denied')) {
            $message .= ' With SELinux enforcing (AlmaLinux, Rocky, RHEL), the guest agent\'s commands are confined: allow them in the template (see docs/guides/os-templates.md).';
        }

        return new self($message);
    }

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
