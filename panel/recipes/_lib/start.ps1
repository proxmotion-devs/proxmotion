# Starts run.ps1 detached (docs/adr/0029 2): the panel runs this through the
# guest agent, which waits for it; the scheduled task it registers runs the
# recipe's script as SYSTEM and survives restarts.
#
#   start.ps1 -Task NAME -Script SCRIPT -RunDir DIR [-Install]
#
# SCRIPT is relative to the app's directory (this file's directory); DIR
# holds the run's inputs.json, status.json, outputs.json, outputs\ and log.
param(
    [Parameter(Mandatory = $true)] [string] $Task,
    [Parameter(Mandatory = $true)] [string] $Script,
    [Parameter(Mandatory = $true)] [string] $RunDir,
    [switch] $Install
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0
$env:PROXMOTION_APP_DIR = $PSScriptRoot
$env:PROXMOTION_RUN_DIR = $RunDir
. (Join-Path $PSScriptRoot 'proxmotion.ps1')

if ($Task -notmatch '^[A-Za-z0-9-]+$') {
    throw "Invalid task name: $Task"
}

# Finished runs leave their task behind (ready, without a trigger) and a
# resumed run disables its start-up task: remove those.
Get-ScheduledTask -TaskPath '\Proxmotion\' -ErrorAction SilentlyContinue |
    Where-Object { $_.State -eq 'Disabled' -or ($_.State -eq 'Ready' -and @($_.Triggers).Count -eq 0) } |
    Unregister-ScheduledTask -Confirm:$false

New-Item -ItemType Directory -Force -Path $RunDir | Out-Null
Remove-Item -LiteralPath (Join-Path $RunDir 'restarts') -Force -ErrorAction SilentlyContinue

$runner = Join-Path $PSScriptRoot 'run.ps1'
$arguments = "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$runner`" -Script `"$Script`" -RunDir `"$RunDir`" -Task `"$Task`""

if ($Install) {
    $arguments += ' -Install'
}

Register-PmTask $Task $arguments
Start-ScheduledTask -TaskName $Task -TaskPath '\Proxmotion\'
