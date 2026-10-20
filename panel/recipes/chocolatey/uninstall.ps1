# Removes Chocolatey itself: its folder and its environment variables.
# Programs installed with it stay installed (but are no longer managed).
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0
. "$env:PROXMOTION_APP_DIR\proxmotion.ps1"
. "$env:PROXMOTION_APP_DIR\lib.ps1"

Pm-Step 30 'Removing Chocolatey'

if (Test-Path -LiteralPath $ChocoRoot) {
    Remove-Item -LiteralPath $ChocoRoot -Recurse -Force
}

$bin = Join-Path $ChocoRoot 'bin'
$path = [Environment]::GetEnvironmentVariable('Path', 'Machine')
$kept = @($path -split ';' | Where-Object { $_ -ne '' -and $_.TrimEnd('\') -ne $bin })
[Environment]::SetEnvironmentVariable('Path', ($kept -join ';'), 'Machine')

foreach ($name in 'ChocolateyInstall', 'ChocolateyLastPathUpdate') {
    [Environment]::SetEnvironmentVariable($name, $null, 'Machine')
    [Environment]::SetEnvironmentVariable($name, $null, 'User')
}
