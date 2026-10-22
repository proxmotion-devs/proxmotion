# Stops app scripts started by start.ps1 (docs/adr/0030): the panel writes
# this file into the app's directory and runs it through the guest agent.
#
#   stop.ps1 NAME…
#
# NAME is a task start.ps1 registered (\Proxmotion\NAME, with start-up tasks
# NAME-boot-N after restarts). A name that is not running counts as stopped;
# the exit code is non-zero only when one of its processes is still alive.
param(
    [Parameter(ValueFromRemainingArguments = $true)] [string[]] $Names = @()
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0

foreach ($name in $Names) {
    if ($name -notmatch '^[A-Za-z0-9-]+$') {
        [Console]::Error.WriteLine("Invalid task name: $name")
        exit 2
    }
}

function Test-PmName([string] $TaskName) {
    foreach ($name in $Names) {
        if ($TaskName -eq $name -or $TaskName -like "$name-boot-*") {
            return $true
        }
    }

    return $false
}

$tasks = @(Get-ScheduledTask -TaskPath '\Proxmotion\' -ErrorAction SilentlyContinue | Where-Object { Test-PmName $_.TaskName })

# No start-up task may resume the script after this.
foreach ($task in $tasks) {
    Disable-ScheduledTask -InputObject $task -ErrorAction SilentlyContinue | Out-Null
    Stop-ScheduledTask -InputObject $task -ErrorAction SilentlyContinue
}

# run.ps1 and its child carry "-Task NAME" on their command line; everything
# they started is found by parent process id (younger than its parent, as
# process ids are reused).
$processes = @(Get-CimInstance -ClassName Win32_Process)
$found = @{}
$queue = New-Object System.Collections.Generic.Queue[object]

foreach ($process in $processes) {
    $line = [string] $process.CommandLine

    if ($process.ProcessId -eq $PID -or $line -eq '') {
        continue
    }

    foreach ($name in $Names) {
        if ($line -match ('-Task\s+"?' + [regex]::Escape($name) + '"?(\s|$)')) {
            $found[[int] $process.ProcessId] = $process
            $queue.Enqueue($process)
            break
        }
    }
}

while ($queue.Count -gt 0) {
    $parent = $queue.Dequeue()

    foreach ($process in $processes) {
        if ($process.ParentProcessId -ne $parent.ProcessId -or $found.ContainsKey([int] $process.ProcessId) -or $process.ProcessId -eq $PID) {
            continue
        }

        if ($null -ne $process.CreationDate -and $null -ne $parent.CreationDate -and $process.CreationDate -lt $parent.CreationDate) {
            continue
        }

        $found[[int] $process.ProcessId] = $process
        $queue.Enqueue($process)
    }
}

foreach ($id in $found.Keys) {
    Stop-Process -Id $id -Force -ErrorAction SilentlyContinue
}

$left = @()

for ($i = 0; $i -lt 10; $i++) {
    $left = @($found.Keys | Where-Object { $null -ne (Get-Process -Id $_ -ErrorAction SilentlyContinue) })

    if ($left.Count -eq 0) {
        break
    }

    Start-Sleep -Seconds 1
}

foreach ($task in $tasks) {
    Unregister-ScheduledTask -TaskName $task.TaskName -TaskPath '\Proxmotion\' -Confirm:$false -ErrorAction SilentlyContinue
}

if ($left.Count -gt 0) {
    [Console]::Error.WriteLine("Still running: process $($left -join ', ').")
    exit 1
}

exit 0
