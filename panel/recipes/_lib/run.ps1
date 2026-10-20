# Runs one script of a Windows recipe (docs/adr/0029), the counterpart of
# run.sh. start.ps1 starts it as a scheduled task; the outcome ends up in
# status.json of the run's directory, which the panel reads through the
# guest agent.
#
#   run.ps1 -Script install.ps1 -RunDir APP_DIR -Task NAME -Install  → "installed"
#   run.ps1 -Script SCRIPT -RunDir RUN_DIR -Task NAME                → "succeeded"
#
# A script that exits with 3010 (Pm-RequestRestart) is run again from the
# beginning after Windows restarted, at most MaxRestarts times per run.
param(
    [Parameter(Mandatory = $true)] [string] $Script,
    [Parameter(Mandatory = $true)] [string] $RunDir,
    [Parameter(Mandatory = $true)] [string] $Task,
    [switch] $Install,
    [switch] $Child
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0

$env:PROXMOTION_APP_DIR = $PSScriptRoot
$env:PROXMOTION_RUN_DIR = $RunDir
. (Join-Path $PSScriptRoot 'proxmotion.ps1')

if ($Child) {
    # The child process running the script: the exit code is the one
    # powershell -File would give, and an error nobody caught becomes the
    # failure reason (its first line), not the last line of PowerShell's report.
    $global:LASTEXITCODE = 0

    try {
        & (Join-Path $PSScriptRoot $Script)
        $ok = $?
        $code = $LASTEXITCODE
    } catch {
        $failure = Join-Path $RunDir 'failure'

        if (-not (Test-Path -LiteralPath $failure)) {
            Write-PmFile $failure "$($_.Exception.Message)`n"
        }

        [Console]::Error.WriteLine("ERROR: $($_.Exception.Message)")
        [Console]::Error.WriteLine($_.InvocationInfo.PositionMessage)
        exit 1
    }

    if ($ok) { exit 0 }
    if ($code) { exit $code }
    exit 1
}

$MaxRestarts = 3

$doneStatus = if ($Install) { 'installed' } else { 'succeeded' }
$doneStep = if ($Install) { 'Installed' } else { 'Done' }
$log = Join-Path $RunDir 'run.log'
$restartsFile = Join-Path $RunDir 'restarts'
$utf8 = New-Object System.Text.UTF8Encoding $false

function Add-Log([string] $Line) {
    [System.IO.File]::AppendAllText($log, "$Line`n", $utf8)
}

function Get-Time {
    return (Get-Date).ToUniversalTime().ToString('HH:mm:ss')
}

function Get-Reason([string] $Fallback) {
    $failure = Join-Path $RunDir 'failure'

    if ((Test-Path -LiteralPath $failure) -and (Get-Item -LiteralPath $failure).Length -gt 0) {
        return @([System.IO.File]::ReadAllLines($failure, $utf8))[0]
    }

    $last = @([System.IO.File]::ReadAllLines($log, $utf8) | Where-Object { $_.Trim() -ne '' } | Select-Object -Last 1)

    return "$Fallback$(if ($last.Count -gt 0) { ': ' + $last[0] })"
}

function Limit([string] $Text) {
    $Text = ($Text -replace '\s+', ' ').Trim()
    if ($Text.Length -gt 500) { return $Text.Substring(0, 500) }
    return $Text
}

New-Item -ItemType Directory -Force -Path $RunDir | Out-Null
Set-Location -LiteralPath $RunDir
$restarts = 0

if (Test-Path -LiteralPath $restartsFile) {
    [void][int]::TryParse(([System.IO.File]::ReadAllText($restartsFile)).Trim(), [ref] $restarts)
}

$resumed = $restarts -gt 0
Remove-Item -LiteralPath 'failure', 'outputs.json' -Force -ErrorAction SilentlyContinue
Remove-Item -LiteralPath 'outputs' -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path 'outputs' | Out-Null

if ($resumed) {
    # Started by the start-up task: it must not run again at the next boot.
    Disable-ScheduledTask -TaskName "$Task-boot-$restarts" -TaskPath '\Proxmotion\' -ErrorAction SilentlyContinue | Out-Null
    Add-Log "==> [$(Get-Time)] Windows restarted ($restarts)"
} else {
    Remove-Item -LiteralPath 'state' -Force -ErrorAction SilentlyContinue
    [System.IO.File]::WriteAllText($log, '', $utf8)
}

if ([System.IO.Path]::IsPathRooted($Script) -or $Script.Contains('..')) {
    Pm-Status 'failed' 0 'Preparing' 'Invalid script path.'
    exit 1
}

try {
    if (-not $resumed) {
        Pm-Step 0 'Preparing' | ForEach-Object { Add-Log $_ }
    }

    # Right after the first boot Windows Installer may still be busy (updates,
    # Cloudbase-Init plugins). The panel gives such installs extra time
    # (AppInstallation::BOOT_WAIT_MINUTES).
    Pm-WaitForInstaller 600 | ForEach-Object { Add-Log $_ }
    Write-PmFile (Join-Path $RunDir 'outputs.json') "{}`n"

    $powershell = Join-Path $PSHOME 'powershell.exe'

    # A child process, so the script's exit and errors end only the script.
    # Its output is appended as UTF-8 (PowerShell's own >> would write UTF-16).
    $ErrorActionPreference = 'Continue'
    & $powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $PSCommandPath -Script $Script -RunDir $RunDir -Task $Task -Child 2>&1 | ForEach-Object { Add-Log "$_" }
    $code = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
} catch {
    $reason = Limit "$($_.Exception.Message)"
    Add-Log "==> [$(Get-Time)] Failed: $reason"
    Pm-Status 'failed' (Pm-Current 'progress') (Pm-Current 'step') $reason
    exit 1
}

if ($code -eq 0) {
    Add-Log "==> [$(Get-Time)] $doneStep"
    Pm-Status $doneStatus 100 $doneStep
    exit 0
}

if ($code -eq $PmRestartExitCode) {
    if ($restarts -ge $MaxRestarts) {
        $reason = 'The script asked for a restart too often.'
        Add-Log "==> [$(Get-Time)] Failed: $reason"
        Pm-Status 'failed' (Pm-Current 'progress') (Pm-Current 'step') $reason
        exit 1
    }

    $restarts++
    Write-PmFile $restartsFile "$restarts`n"
    Pm-Step (Pm-Current 'progress') 'Restarting Windows' | ForEach-Object { Add-Log $_ }

    $runner = Join-Path $PSScriptRoot 'run.ps1'
    $arguments = "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$runner`" -Script `"$Script`" -RunDir `"$RunDir`" -Task `"$Task`""

    if ($Install) {
        $arguments += ' -Install'
    }

    try {
        Register-PmTask "$Task-boot-$restarts" $arguments -AtStartup
        Restart-Computer -Force
    } catch {
        $reason = Limit "Windows could not be restarted: $($_.Exception.Message)"
        Add-Log "==> [$(Get-Time)] Failed: $reason"
        Pm-Status 'failed' (Pm-Current 'progress') (Pm-Current 'step') $reason
        exit 1
    }

    exit 0
}

$reason = Limit (Get-Reason "exit code $code")
Add-Log "==> [$(Get-Time)] Failed: $reason"
Pm-Status 'failed' (Pm-Current 'progress') (Pm-Current 'step') $reason
exit $code
