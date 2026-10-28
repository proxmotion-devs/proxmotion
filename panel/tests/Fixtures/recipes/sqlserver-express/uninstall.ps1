# Removes the SQLEXPRESS instance with SQL Server's own setup and the firewall
# rule. Database files in the instance's DATA folder stay on disk.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0
. "$env:PROXMOTION_APP_DIR\proxmotion.ps1"
. "$env:PROXMOTION_APP_DIR\lib.ps1"

Pm-Step 20 'Closing the SQL Server port'
Pm-RemoveFirewallPort 'sql'

if (Test-SqlInstalled) {
    Pm-Step 40 'Removing SQL Server'
    $setup = Get-ChildItem -Path "$env:ProgramFiles\Microsoft SQL Server\*\Setup Bootstrap\SQL2022\setup.exe" -ErrorAction SilentlyContinue | Select-Object -First 1

    if ($null -eq $setup) {
        Pm-Fail 'SQL Server setup was not found; remove SQL Server in Settings > Apps.'
    }

    Pm-Exec $setup.FullName @('/Q', '/ACTION=Uninstall', '/FEATURES=SQLEngine', "/INSTANCENAME=$SqlInstance") -SuccessCodes 0, 3010

    if ($LASTEXITCODE -eq 3010) {
        Pm-RequestRestart
    }
}

Remove-Item -LiteralPath $SqlPasswordFile -Force -ErrorAction SilentlyContinue
