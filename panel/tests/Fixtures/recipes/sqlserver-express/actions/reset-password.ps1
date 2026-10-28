# Gives sa a new password (and enables it).
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0
. "$env:PROXMOTION_APP_DIR\proxmotion.ps1"
. "$env:PROXMOTION_APP_DIR\lib.ps1"

if (-not (Test-SqlInstalled)) {
    Pm-Fail 'SQL Server is not installed.'
}

Pm-Step 30 'Setting a new sa password'
Wait-SqlService
$password = Get-SqlPassword -New
Set-SqlSaPassword $password

Pm-Output 'username' 'sa'
Pm-Output 'password' $password
