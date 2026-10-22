# Lists the packages Chocolatey installed, one "id version" per line.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0
. "$env:PROXMOTION_APP_DIR\proxmotion.ps1"
. "$env:PROXMOTION_APP_DIR\lib.ps1"

if (-not (Test-Choco)) {
    Pm-Fail 'Chocolatey is not installed.'
}

Pm-Step 50 'Listing packages'
$lines = @(& $Choco list --limit-output | Where-Object { $_ -match '\|' } | ForEach-Object { $_ -replace '\|', ' ' })

Pm-Output 'packages' ($lines -join "`n")
