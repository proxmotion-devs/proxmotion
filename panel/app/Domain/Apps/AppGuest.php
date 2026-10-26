<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\OsPlatform;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\Resources\GuestAgentResource;

/**
 * What differs between Linux and Windows inside the server when apps run
 * (docs/adr/0029): where apps live, the shared files written next to a
 * recipe, and the commands that make directories, start and stop the runner
 * (docs/adr/0030), read a log chunk and remove an app's directory.
 * Everything else (status.json, outputs, run.log) is the same on both.
 */
final class AppGuest
{
    public const string LINUX_ROOT = '/var/lib/proxmotion/apps';

    /** Forward slashes: the Windows file API and PowerShell accept them; cmd.exe gets backslashes. */
    public const string WINDOWS_ROOT = 'C:/ProgramData/Proxmotion/apps';

    /** Shared files the panel writes next to every recipe (panel/recipes/_lib). */
    public const array LIBRARY_FILES = [
        'linux' => ['proxmotion.sh', 'run.sh'],
        'windows' => ['proxmotion.ps1', 'run.ps1', 'start.ps1', 'read-log.ps1'],
    ];

    /** The script that stops runners (panel/recipes/_lib, docs/adr/0030), written only when something is stopped. */
    public const array STOP_FILES = ['linux' => 'stop.sh', 'windows' => 'stop.ps1'];

    /** Full control for SYSTEM and Administrators only, by SID (names are localized). */
    private const array WINDOWS_ACL = ['/inheritance:r', '/grant:r', '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', '/Q'];

    private const array POWERSHELL = ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File'];

    public static function directory(OsPlatform $platform, string $uuid): string
    {
        return ($platform === OsPlatform::Windows ? self::WINDOWS_ROOT : self::LINUX_ROOT).'/'.$uuid;
    }

    /**
     * Create directories only SYSTEM/root can read: inputs may hold passwords.
     *
     * @param  list<string>  $directories
     *
     * @throws ProxmoxException
     */
    public static function makeDirectories(GuestAgentResource $agent, int $vmid, OsPlatform $platform, array $directories): void
    {
        if ($platform === OsPlatform::Linux) {
            $agent->runOrFail($vmid, ['mkdir', '-p', '-m', '700', ...$directories]);

            return;
        }

        // C:\ProgramData is readable by every user: lock the apps directory first.
        // mkdir fails harmlessly for a directory that exists; a write into one that
        // could not be made fails afterwards.
        $agent->run($vmid, ['cmd.exe', '/c', 'mkdir', self::windowsPath(self::WINDOWS_ROOT)]);
        $agent->runOrFail($vmid, ['icacls', self::windowsPath(self::WINDOWS_ROOT), ...self::WINDOWS_ACL]);
        $agent->run($vmid, ['cmd.exe', '/c', 'mkdir', ...array_map(self::windowsPath(...), $directories)]);
    }

    /**
     * The command that starts the runner detached: the install script without
     * $runDirectory, otherwise an uninstall, upgrade or action.
     *
     * @return non-empty-list<string>
     */
    public static function startCommand(OsPlatform $platform, string $appDirectory, string $name, string $script, ?string $runDirectory = null): array
    {
        if ($platform === OsPlatform::Linux) {
            return [
                'systemd-run', '--unit='.$name, '--collect', '--quiet',
                '/bin/bash', $appDirectory.'/run.sh', ...($runDirectory === null ? [] : [$script, $runDirectory]),
            ];
        }

        return [
            ...self::POWERSHELL, $appDirectory.'/start.ps1',
            '-Task', $name, '-Script', $script, '-RunDir', $runDirectory ?? $appDirectory,
            ...($runDirectory === null ? ['-Install'] : []),
        ];
    }

    /**
     * The systemd unit or scheduled task an install attempt runs as.
     */
    public static function installName(string $installation, int $attempt): string
    {
        return 'proxmotion-app-'.$installation.'-'.$attempt;
    }

    /**
     * The systemd unit or scheduled task an uninstall, upgrade or action runs as.
     */
    public static function runName(string $run): string
    {
        return 'proxmotion-app-run-'.$run;
    }

    /**
     * The command that stops the runners started as $names (docs/adr/0030).
     * On Linux the script is part of the command; on Windows it must have
     * been written into $appDirectory first.
     *
     * @param  non-empty-list<string>  $names
     * @return non-empty-list<string>
     */
    public static function stopCommand(OsPlatform $platform, string $appDirectory, string $script, array $names): array
    {
        return $platform === OsPlatform::Linux
            ? ['/bin/bash', '-c', $script, 'proxmotion-stop', ...$names]
            : [...self::POWERSHELL, $appDirectory.'/'.self::STOP_FILES['windows'], ...$names];
    }

    /**
     * The command printing $bytes of $file from $offset as base64. The file,
     * offset and length are arguments, never part of a script.
     *
     * @return non-empty-list<string>
     */
    public static function readCommand(OsPlatform $platform, string $appDirectory, string $file, int $offset, int $bytes): array
    {
        return $platform === OsPlatform::Linux
            ? ['/bin/bash', '-c', AppLogs::READ_SCRIPT, 'proxmotion-log', $file, (string) $offset, (string) $bytes]
            : [...self::POWERSHELL, $appDirectory.'/read-log.ps1', $file, (string) $offset, (string) $bytes];
    }

    /**
     * @return non-empty-list<string>
     */
    public static function removeCommand(OsPlatform $platform, string $directory): array
    {
        return $platform === OsPlatform::Linux
            ? ['rm', '-rf', '--one-file-system', $directory]
            : ['cmd.exe', '/c', 'rmdir', '/s', '/q', self::windowsPath($directory)];
    }

    /**
     * A file the runner writes: PowerShell 5.1 reads a .ps1 file without a
     * byte order mark in the ANSI code page, so one is added.
     */
    public static function content(string $path, string $content): string
    {
        return str_ends_with($path, '.ps1') && ! str_starts_with($content, "\u{FEFF}") ? "\u{FEFF}".$content : $content;
    }

    public static function windowsPath(string $path): string
    {
        return str_replace('/', '\\', $path);
    }
}
