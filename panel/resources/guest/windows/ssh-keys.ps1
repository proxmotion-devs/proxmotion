# Proxmotion: the SSH keys the panel manages on the server (docs/adr/0010 and
# 0049 in the Proxmotion repository). Installed and run by Proxmotion through
# the QEMU guest agent; changes to this file are overwritten.
#
# C:\ProgramData\Proxmotion\ssh-keys.json (written by Proxmotion):
#   { "username": "Administrator",
#     "ssh_keys": ["ssh-ed25519 AAAA... user@host"],
#     "removed_ssh_keys": ["ssh-ed25519 AAAA... old@host"] }
#
# OpenSSH Server reads the keys of administrators from
# C:\ProgramData\ssh\administrators_authorized_keys, and only while SYSTEM and
# Administrators alone may access it. Removes every line of that file that
# contains the key of a removed_ssh_keys line, appends each key of ssh_keys
# that is not there yet, and takes removed keys out of the user's own
# .ssh\authorized_keys too (Cloudbase-Init puts the keys of a new server
# there). Lines Proxmotion does not know stay. Safe to run any number of times.
$ErrorActionPreference = 'Stop'

$dir = 'C:\ProgramData\Proxmotion'
$config = Get-Content -Raw -Path (Join-Path $dir 'ssh-keys.json') | ConvertFrom-Json
$user = [string]$config.username

if ($user -notmatch '^[A-Za-z0-9._-]+$') {
    [Console]::Error.WriteLine("Invalid user name: $user")
    exit 2
}

# A key is told by its base64 blob, the field after its type; options may
# come before the type, so every field of a line is compared. Blobs are
# case-sensitive, so no PowerShell hashtables.
$wanted = New-Object 'System.Collections.Generic.Dictionary[string,string]'
$order = New-Object 'System.Collections.Generic.List[string]'
$removed = New-Object 'System.Collections.Generic.HashSet[string]'

foreach ($line in @($config.ssh_keys)) {
    $fields = ([string]$line).Trim() -split '\s+'
    if ($fields.Count -ge 2 -and -not $wanted.ContainsKey($fields[1])) {
        $wanted[$fields[1]] = ([string]$line).Trim()
        $order.Add($fields[1])
    }
}

foreach ($line in @($config.removed_ssh_keys)) {
    $fields = ([string]$line).Trim() -split '\s+'
    if ($fields.Count -ge 2 -and -not $wanted.ContainsKey($fields[1])) {
        [void]$removed.Add($fields[1])
    }
}

# The lines of a file without the removed keys; $present collects the wanted
# keys found on the kept lines.
function Get-KeptLines([string] $path, $present) {
    $kept = New-Object 'System.Collections.Generic.List[string]'

    if (-not (Test-Path -LiteralPath $path)) {
        return , $kept
    }

    foreach ($line in [System.IO.File]::ReadAllLines($path)) {
        $fields = $line.Trim() -split '\s+'

        if (@($fields | Where-Object { $removed.Contains($_) }).Count -gt 0) {
            continue
        }

        foreach ($field in $fields) {
            if ($wanted.ContainsKey($field)) {
                [void]$present.Add($field)
            }
        }

        $kept.Add($line)
    }

    return , $kept
}

# UTF-8 without a byte order mark, which sshd would read as part of a key.
function Write-Lines([string] $path, $lines) {
    $text = if ($lines.Count -gt 0) { ($lines -join "`r`n") + "`r`n" } else { '' }
    [System.IO.File]::WriteAllText($path, $text, (New-Object System.Text.UTF8Encoding($false)))
}

$sshDir = Join-Path $env:ProgramData 'ssh'
$adminFile = Join-Path $sshDir 'administrators_authorized_keys'
New-Item -ItemType Directory -Force -Path $sshDir | Out-Null

$present = New-Object 'System.Collections.Generic.HashSet[string]'
$lines = Get-KeptLines $adminFile $present

foreach ($blob in $order) {
    if (-not $present.Contains($blob)) {
        $lines.Add($wanted[$blob])
    }
}

Write-Lines $adminFile $lines

# Administrators (S-1-5-32-544) and SYSTEM (S-1-5-18) only, by SID so that
# localized group names do not matter. /reset first drops explicit entries
# (e.g. Users) that /inheritance:r would keep and sshd would refuse.
foreach ($arguments in @(
        @('/reset'),
        @('/inheritance:r', '/grant:r', '*S-1-5-32-544:F', '/grant:r', '*S-1-5-18:F')
    )) {
    & icacls.exe $adminFile @arguments | Out-Null

    if ($LASTEXITCODE -ne 0) {
        [Console]::Error.WriteLine("icacls $($arguments[0]) failed with exit code $LASTEXITCODE.")
        exit 1
    }
}

$userFile = Join-Path $env:SystemDrive "Users\$user\.ssh\authorized_keys"

if ($removed.Count -gt 0 -and (Test-Path -LiteralPath $userFile)) {
    $ignored = New-Object 'System.Collections.Generic.HashSet[string]'
    Write-Lines $userFile (Get-KeptLines $userFile $ignored)
}
