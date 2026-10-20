# Helpers for Windows app recipes (docs/adr/0029), the counterpart of
# proxmotion.sh. The panel writes this file next to a recipe's scripts in
# C:\ProgramData\Proxmotion\apps\<installation>\ and the runner (run.ps1)
# sets PROXMOTION_APP_DIR and PROXMOTION_RUN_DIR. Scripts dot-source it with:
#
#   . "$env:PROXMOTION_APP_DIR\proxmotion.ps1"
#
# Inputs are only ever read from inputs.json (Pm-Input), never interpolated
# into a script by the panel. Progress goes to status.json, which the panel
# reads through the guest agent; outputs go to outputs.json and outputs\.

if (-not $env:PROXMOTION_APP_DIR) {
    throw 'PROXMOTION_APP_DIR is not set'
}

# Where this run keeps its status, inputs and outputs (docs/adr/0016 4): the
# app's directory for the install, runs\<id> for an uninstall or an action.
if (-not $env:PROXMOTION_RUN_DIR) {
    $env:PROXMOTION_RUN_DIR = $env:PROXMOTION_APP_DIR
}

[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding $false
$OutputEncoding = [Console]::OutputEncoding

# The exit code that asks the runner for a restart (Windows Installer's "restart required").
$PmRestartExitCode = 3010

# Write-PmFile PATH TEXT: UTF-8 without a byte order mark, replaced atomically.
function Write-PmFile([string] $Path, [string] $Text) {
    $tmp = "$Path.tmp"
    [System.IO.File]::WriteAllText($tmp, $Text, (New-Object System.Text.UTF8Encoding $false))
    Move-Item -LiteralPath $tmp -Destination $Path -Force
}

# Read-PmJson PATH: a JSON file as an object (empty when it is missing).
function Read-PmJson([string] $Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        return New-Object PSObject
    }

    $text = [System.IO.File]::ReadAllText($Path, [System.Text.Encoding]::UTF8)

    if ($text.Trim() -eq '') {
        return New-Object PSObject
    }

    return $text | ConvertFrom-Json
}

# Pm-Status STATUS PROGRESS STEP [MESSAGE]: replace status.json.
function Pm-Status([string] $Status, [int] $Progress, [string] $Step, [string] $Message = '') {
    $json = [ordered]@{
        status = $Status
        progress = $Progress
        step = $Step
        message = $Message
        updated_at = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    } | ConvertTo-Json -Compress
    Write-PmFile (Join-Path $env:PROXMOTION_RUN_DIR 'status.json') "$json`n"
}

# Pm-Step PROGRESS MESSAGE: report the step the script is starting (0-99).
function Pm-Step([int] $Progress, [string] $Message) {
    Write-PmFile (Join-Path $env:PROXMOTION_RUN_DIR 'state') "$Progress`n$Message`n"
    Pm-Status 'installing' $Progress $Message
    Write-Output "==> [$((Get-Date).ToUniversalTime().ToString('HH:mm:ss'))] $Message"
}

# Pm-Current progress|step: the last reported step.
function Pm-Current([string] $What) {
    $file = Join-Path $env:PROXMOTION_RUN_DIR 'state'

    if (-not (Test-Path -LiteralPath $file)) {
        if ($What -eq 'progress') { return 0 }
        return ''
    }

    $lines = [System.IO.File]::ReadAllLines($file, [System.Text.Encoding]::UTF8)

    if ($What -eq 'progress') {
        $progress = 0
        if ($lines.Count -gt 0) { [void][int]::TryParse($lines[0], [ref] $progress) }
        return $progress
    }

    if ($lines.Count -gt 1) { return $lines[1] }
    return ''
}

# Pm-Fail MESSAGE: stop the script with a message shown to the customer.
function Pm-Fail([string] $Message) {
    Write-PmFile (Join-Path $env:PROXMOTION_RUN_DIR 'failure') "$Message`n"
    [Console]::Error.WriteLine("ERROR: $Message")
    exit 1
}

# Pm-RequestRestart: end the script and let the runner restart Windows and
# run it again from the beginning (docs/adr/0029 4).
function Pm-RequestRestart {
    Write-Output 'Windows needs a restart; the script runs again afterwards.'
    exit $PmRestartExitCode
}

function Get-PmValue([string] $Path, [string] $Key) {
    $data = Read-PmJson $Path
    $property = $data.PSObject.Properties[$Key]

    if ($null -eq $property -or $null -eq $property.Value) {
        return ''
    }

    if ($property.Value -is [bool]) {
        return $property.Value.ToString().ToLowerInvariant()
    }

    return [string] $property.Value
}

# Pm-Input KEY: the validated value of an input (empty when not given).
function Pm-Input([string] $Key) {
    return Get-PmValue (Join-Path $env:PROXMOTION_RUN_DIR 'inputs.json') $Key
}

# Pm-AppInput KEY: an input the app was installed with (in an uninstall or an
# action, where Pm-Input reads the action's own inputs).
function Pm-AppInput([string] $Key) {
    return Get-PmValue (Join-Path $env:PROXMOTION_APP_DIR 'inputs.json') $Key
}

# Pm-Server KEY: hostname, primary_ipv4 or primary_ipv6 of the server (empty when it has none).
function Pm-Server([string] $Key) {
    return Get-PmValue (Join-Path $env:PROXMOTION_APP_DIR 'server.json') $Key
}

# Pm-Output KEY VALUE: a text or URL output.
function Pm-Output([string] $Key, [string] $Value) {
    $file = Join-Path $env:PROXMOTION_RUN_DIR 'outputs.json'
    $outputs = [ordered]@{}
    $data = Read-PmJson $file

    foreach ($property in $data.PSObject.Properties) {
        $outputs[$property.Name] = $property.Value
    }

    $outputs[$Key] = $Value
    Write-PmFile $file (($outputs | ConvertTo-Json -Compress) + "`n")
}

# Pm-OutputFile FILENAME SOURCE: a file output (the recipe declares FILENAME).
function Pm-OutputFile([string] $Filename, [string] $Source) {
    Copy-Item -LiteralPath $Source -Destination (Join-Path (Join-Path $env:PROXMOTION_RUN_DIR 'outputs') $Filename) -Force
}

# Pm-PublicAddress: the address clients connect to (primary IPv4, else IPv6, else the hostname).
function Pm-PublicAddress {
    foreach ($key in 'primary_ipv4', 'primary_ipv6', 'hostname') {
        $address = Pm-Server $key

        if ($address -ne '') {
            return $address
        }
    }

    return ''
}

# Pm-Exec PROGRAM ARGUMENTS [-SuccessCodes]: run a native program and fail on
# an unexpected exit code (native programs never stop PowerShell on their own).
function Pm-Exec {
    param(
        [Parameter(Mandatory = $true)] [string] $Program,
        [string[]] $Arguments = @(),
        [int[]] $SuccessCodes = @(0)
    )

    # With "Stop", PowerShell 5.1 turns the first line a program writes to stderr into an error.
    $ErrorActionPreference = 'Continue'
    & $Program @Arguments 2>&1 | ForEach-Object { "$_" }
    $code = $LASTEXITCODE

    if ($SuccessCodes -notcontains $code) {
        throw "$([System.IO.Path]::GetFileName($Program)) exited with code $code."
    }

    return
}

# Pm-IsServer: whether this is Windows Server (not Windows 10/11).
function Pm-IsServer {
    return (Get-CimInstance -ClassName Win32_OperatingSystem).ProductType -ne 1
}

# Pm-Feature -Server NAMES -Desktop NAMES: turn on Windows features
# (Install-WindowsFeature on Windows Server, optional features on the
# desktop). Returns whether Windows needs a restart for them.
function Pm-Feature {
    param([string[]] $Server = @(), [string[]] $Desktop = @())

    if (Pm-IsServer) {
        $result = Install-WindowsFeature -Name $Server -IncludeManagementTools -ErrorAction Stop

        if (-not $result.Success) {
            throw "Windows features could not be installed: $($Server -join ', ')."
        }

        return [string] $result.RestartNeeded -eq 'Yes'
    }

    $restart = $false

    foreach ($name in $Desktop) {
        $result = Enable-WindowsOptionalFeature -Online -FeatureName $name -All -NoRestart -ErrorAction Stop
        $restart = $restart -or [bool] $result.RestartNeeded
    }

    return $restart
}

# Pm-RemoveFeature -Server NAMES -Desktop NAMES: the reverse of Pm-Feature.
function Pm-RemoveFeature {
    param([string[]] $Server = @(), [string[]] $Desktop = @())

    if (Pm-IsServer) {
        $result = Uninstall-WindowsFeature -Name $Server -ErrorAction Stop

        return [string] $result.RestartNeeded -eq 'Yes'
    }

    $restart = $false

    foreach ($name in $Desktop) {
        $result = Disable-WindowsOptionalFeature -Online -FeatureName $name -NoRestart -ErrorAction Stop
        $restart = $restart -or [bool] $result.RestartNeeded
    }

    return $restart
}

# Pm-Download URL FILE: download over HTTPS (TLS 1.2 turned on for older Windows).
function Pm-Download([string] $Url, [string] $File) {
    if (-not $Url.StartsWith('https://')) {
        throw "Only HTTPS downloads are allowed: $Url"
    }

    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    $progress = $ProgressPreference
    $ProgressPreference = 'SilentlyContinue'

    try {
        Invoke-WebRequest -Uri $Url -OutFile $File -UseBasicParsing
    } finally {
        $ProgressPreference = $progress
    }
}

# Pm-RuleName NAME: the firewall rule name of this installation.
function Pm-RuleName([string] $Name) {
    return "Proxmotion $(Split-Path -Leaf $env:PROXMOTION_APP_DIR) $Name"
}

# Pm-FirewallPort NAME PROTOCOL PORTS: allow inbound TCP or UDP ports, one
# rule per name, replaced when it exists.
function Pm-FirewallPort([string] $Name, [string] $Protocol, [string[]] $Ports) {
    $rule = Pm-RuleName $Name
    Get-NetFirewallRule -Name $rule -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    New-NetFirewallRule -Name $rule -DisplayName $rule -Direction Inbound -Action Allow -Protocol $Protocol -LocalPort $Ports | Out-Null
}

# Pm-RemoveFirewallPort NAME: remove a rule Pm-FirewallPort made.
function Pm-RemoveFirewallPort([string] $Name) {
    Get-NetFirewallRule -Name (Pm-RuleName $Name) -ErrorAction SilentlyContinue | Remove-NetFirewallRule
}

# Pm-RandomPassword [LENGTH]: letters and digits only, at least one of each kind.
function Pm-RandomPassword([int] $Length = 20) {
    $sets = 'ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789'
    $all = -join $sets
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $bytes = New-Object byte[] $Length
    $rng.GetBytes($bytes)
    $chars = for ($i = 0; $i -lt $Length; $i++) {
        $set = if ($i -lt $sets.Count) { $sets[$i] } else { $all }
        $set[$bytes[$i] % $set.Length]
    }

    return -join ($chars | Sort-Object { Get-Random })
}

# Register-PmTask NAME ARGUMENTS [-AtStartup]: the scheduled task that runs
# run.ps1 as SYSTEM (start.ps1, and run.ps1 before a restart; docs/adr/0029 2).
function Register-PmTask([string] $Name, [string] $Arguments, [switch] $AtStartup) {
    $action = New-ScheduledTaskAction -Execute (Join-Path $PSHOME 'powershell.exe') -Argument $Arguments -WorkingDirectory $env:PROXMOTION_APP_DIR
    $principal = New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew
    $task = @{ TaskName = $Name; TaskPath = '\Proxmotion\'; Action = $action; Principal = $principal; Settings = $settings; Force = $true }

    if ($AtStartup) {
        $task.Trigger = New-ScheduledTaskTrigger -AtStartup
    }

    Register-ScheduledTask @task | Out-Null
}

# Pm-WaitForInstaller [SECONDS]: wait while Windows Installer is busy (an
# update or another setup right after the first boot), at most SECONDS.
function Pm-WaitForInstaller([int] $Seconds = 600) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    $announced = $false

    while ((Get-Date) -lt $deadline) {
        $mutex = $null

        if (-not [System.Threading.Mutex]::TryOpenExisting('Global\_MSIExecute', [ref] $mutex)) {
            return
        }

        $busy = $true

        try {
            # The mutex exists while msiexec runs; it can be taken only when no install holds it.
            if ($mutex.WaitOne(0)) {
                $mutex.ReleaseMutex()
                $busy = $false
            }
        } catch [System.Threading.AbandonedMutexException] {
            $busy = $false
        } catch {
            $busy = $true
        } finally {
            $mutex.Dispose()
        }

        if (-not $busy) {
            return
        }

        if (-not $announced) {
            Pm-Step (Pm-Current 'progress') 'Waiting for Windows Installer'
            $announced = $true
        }

        Start-Sleep -Seconds 5
    }
}
