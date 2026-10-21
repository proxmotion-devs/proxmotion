# SQL Server 2022 Express (instance SQLEXPRESS) through Microsoft's installer:
# the Core package is downloaded and checked, setup installs the database
# engine with SQL logins, then the instance gets a fixed TCP port.
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

    # The Core package itself, not the Express bootstrapper: Microsoft's
    # bootstrapper link now hands out SQL Server 2025, whose setup fails on
    # Windows releases without GetNumaNodeProcessorMask2 (before Server 2022).
    Pm-Step 10 'Downloading SQL Server 2022 Express'
    $package = Join-Path $media 'SQLEXPR_x64_ENU.exe'
    Pm-Download $SqlPackageUrl $package

    if ((Get-FileHash -LiteralPath $package -Algorithm SHA256).Hash -ne $SqlPackageSha256) {
        Pm-Fail 'The downloaded SQL Server package is not the expected SQL Server 2022 Express (SHA-256 differs).'
    }

    Pm-Step 25 'Unpacking SQL Server'
    $setupDir = Join-Path $media 'setup'
    Pm-Exec $package @('/Q', "/X:$setupDir")

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
