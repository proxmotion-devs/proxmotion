# Proxmotion: the server's hostname (docs/adr/0010 and 0012 in the Proxmotion
# repository). Installed and run by Proxmotion through the QEMU guest agent;
# changes to this file are overwritten.
#
# C:\ProgramData\Proxmotion\hostname.json (written by Proxmotion):
#   { "hostname": "web1.example.com", "previous_hostname": "web.example.com" }
#
# Renames the computer to the first label of the hostname (NetBIOS names have
# at most 15 characters). Windows applies it at the next restart, which
# Proxmotion does when asked. Safe to run any number of times.
$ErrorActionPreference = 'Stop'

$dir = 'C:\ProgramData\Proxmotion'
$config = Get-Content -Raw -Path (Join-Path $dir 'hostname.json') | ConvertFrom-Json
$name = ([string]$config.hostname).Split('.')[0]

if ($name -notmatch '^[A-Za-z0-9][A-Za-z0-9-]{0,14}$') {
    [Console]::Error.WriteLine("The computer name must be 1 to 15 letters, digits or hyphens: $name")
    exit 2
}

# Right after an install Cloudbase-Init may still set its own name; wait for it.
$deadline = (Get-Date).AddMinutes(4)
while ((Get-Service -Name 'cloudbase-init' -ErrorAction SilentlyContinue).Status -eq 'Running' -and (Get-Date) -lt $deadline) {
    Start-Sleep -Seconds 5
}

# The name Windows uses after the next restart (may already be pending).
$pending = (Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\ComputerName\ComputerName').ComputerName

if ($pending -ine $name) {
    Rename-Computer -NewName $name -Force -WarningAction SilentlyContinue
}
