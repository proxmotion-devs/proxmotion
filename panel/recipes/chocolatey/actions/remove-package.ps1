# Uninstalls a package Chocolatey installed.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0
. "$env:PROXMOTION_APP_DIR\proxmotion.ps1"
. "$env:PROXMOTION_APP_DIR\lib.ps1"

if (-not (Test-Choco)) {
    Pm-Fail 'Chocolatey is not installed.'
}

$package = Get-ChocoPackage 'package'

Pm-Step 20 "Removing $package"
Pm-Exec $Choco @('uninstall', $package, '--yes', '--no-progress', '--remove-dependencies') -SuccessCodes $ChocoSuccess
