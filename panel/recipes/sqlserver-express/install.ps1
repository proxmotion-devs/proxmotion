# SQL Server 2022 Express (instance SQLEXPRESS) through Microsoft's installer:
# the Express bootstrapper downloads the Core package, setup installs the
# database engine with SQL logins, then the instance gets a fixed TCP port.
# Running it again keeps the instance and its databases and the sa password.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0
. "$env:PROXMOTION_APP_DIR\proxmotion.ps1"
. "$env:PROXMOTION_APP_DIR\lib.ps1"

$port = Pm-Input 'port'
$password = Get-SqlPassword
$restart = $false

if (-not (Test-SqlInstalled)) {
    $media = Join-Path $env:PROXMOTION_APP_DIR 'media'
    New-Item -ItemType Directory -Force -Path $media | Out-Null

    Pm-Step 10 'Downloading SQL Server 2022 Express'
    $bootstrapper = Join-Path $media 'SQL2022-SSEI-Expr.exe'
    Pm-Download 'https://go.microsoft.com/fwlink/p/?linkid=2216019' $bootstrapper
    Pm-Exec $bootstrapper @('/ACTION=Download', "/MEDIAPATH=$media", '/MEDIATYPE=Core', '/QUIET')
    $package = Get-ChildItem -LiteralPath $media -Filter 'SQLEXPR*.exe' | Select-Object -First 1

    if ($null -eq $package) {
        Pm-Fail 'The SQL Server installer did not download its package.'
    }

    Pm-Step 25 'Unpacking SQL Server'
    $setupDir = Join-Path $media 'setup'
    Pm-Exec $package.FullName @('/Q', "/X:$setupDir")

    Pm-Step 35 'Installing SQL Server (this takes several minutes)'
    $arguments = @(
        '/Q', '/ACTION=Install', '/FEATURES=SQLEngine', "/INSTANCENAME=$SqlInstance",
        '/IACCEPTSQLSERVERLICENSETERMS', '/UPDATEENABLED=False', '/TCPENABLED=1',
        '/SECURITYMODE=SQL', "/SAPWD=$password",
        # SYSTEM runs this recipe's later scripts; Administrators are the customer.
        "/SQLSYSADMINACCOUNTS=$(Get-SqlAccount 'S-1-5-18')", (Get-SqlAccount 'S-1-5-32-544')
    )
    Pm-Exec (Join-Path $setupDir 'setup.exe') $arguments -SuccessCodes 0, 3010
    $restart = $LASTEXITCODE -eq 3010
    Remove-Item -LiteralPath $media -Recurse -Force
}

Pm-Step 80 "Listening on TCP port $port"
$id = (Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Microsoft SQL Server\Instance Names\SQL').$SqlInstance
$tcp = "HKLM:\SOFTWARE\Microsoft\Microsoft SQL Server\$id\MSSQLServer\SuperSocketNetLib\Tcp\IPAll"
Set-ItemProperty -Path $tcp -Name 'TcpDynamicPorts' -Value ''
Set-ItemProperty -Path $tcp -Name 'TcpPort' -Value "$port"
Restart-Service -Name $SqlService -Force
Pm-FirewallPort 'sql' 'TCP' @("$port")

Pm-Step 90 'Setting the sa password'
Wait-SqlService
Set-SqlSaPassword $password

Pm-Output 'server' "$(Pm-PublicAddress),$port"
Pm-Output 'username' 'sa'
Pm-Output 'password' $password
Pm-Output 'notice' "Instance $SqlInstance; connect with SQL Server authentication. Express databases are limited to 10 GB each."

# Setup asked for a restart: do it now; the script then only checks the instance again.
if ($restart) {
    Pm-RequestRestart
}
