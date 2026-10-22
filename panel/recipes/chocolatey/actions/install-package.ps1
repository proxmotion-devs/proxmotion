# Installs (or upgrades to) a package from the Chocolatey community repository.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0
. "$env:PROXMOTION_APP_DIR\proxmotion.ps1"
. "$env:PROXMOTION_APP_DIR\lib.ps1"

if (-not (Test-Choco)) {
    Pm-Fail 'Chocolatey is not installed.'
}

$package = Get-ChocoPackage 'package'
$version = Pm-Input 'version'
$arguments = @('upgrade', $package, '--yes', '--no-progress')

if ($version -ne '') {
    if ($version -notmatch '^[0-9][A-Za-z0-9.-]{0,49}$') {
        Pm-Fail "Not a package version: $version"
    }

    $arguments += @('--version', $version, '--allow-downgrade')
}

# choco upgrade installs a package that is missing.
Pm-Step 20 "Installing $package"
Pm-Exec $Choco $arguments -SuccessCodes $ChocoSuccess

$installed = & $Choco list --limit-output --exact $package | Select-Object -Last 1
Pm-Output 'installed' ($installed -replace '\|', ' ')
