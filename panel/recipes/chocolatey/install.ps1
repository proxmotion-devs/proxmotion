# Chocolatey through its official install script
# (https://docs.chocolatey.org/en-us/choco/setup). Chocolatey needs .NET
# Framework 4.8, which older Windows (Server 2016) lacks: it is installed
# first from Microsoft's offline installer, with a restart. Running it again
# keeps Chocolatey and its packages.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0
. "$env:PROXMOTION_APP_DIR\proxmotion.ps1"
. "$env:PROXMOTION_APP_DIR\lib.ps1"

if (-not (Test-Choco)) {
    # .NET Framework 4.8 is release 528040 or later.
    $release = (Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full' -ErrorAction SilentlyContinue).Release

    if ($null -eq $release -or $release -lt 528040) {
        Pm-Step 10 'Installing .NET Framework 4.8'
        $installer = Join-Path $env:PROXMOTION_APP_DIR 'ndp48.exe'
        Pm-Download 'https://go.microsoft.com/fwlink/?linkid=2088631' $installer
        Pm-Exec $installer @('/q', '/norestart') -SuccessCodes 0, 1641, 3010
        Remove-Item -LiteralPath $installer -Force
        Pm-RequestRestart
    }

    Pm-Step 40 'Installing Chocolatey'
    $script = Join-Path $env:PROXMOTION_APP_DIR 'chocolatey-install.ps1'
    Pm-Download 'https://community.chocolatey.org/install.ps1' $script
    Pm-Exec (Join-Path $PSHOME 'powershell.exe') @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script)
    Remove-Item -LiteralPath $script -Force

    if (-not (Test-Choco)) {
        Pm-Fail 'The Chocolatey install script finished without installing choco.exe.'
    }
}

Pm-Step 90 'Checking Chocolatey'
$version = (& $Choco --version | Select-Object -Last 1).Trim()

Pm-Output 'version' $version
