# Shared by the SQL Server Express scripts.

$SqlInstance = 'SQLEXPRESS'
$SqlService = 'MSSQL$SQLEXPRESS'
# SQL Server 2022 Express 16.0.1000.6, Core package (English), signed by Microsoft.
$SqlPackageUrl = 'https://download.microsoft.com/download/3/8/d/38de7036-2433-4207-8eae-06e247e17b25/SQLEXPR_x64_ENU.exe'
$SqlPackageSha256 = '2E61C8BBDE6021F9026C54AD9DB4BBB1227E68761D4C00A6A50A2C70FE7AFE05'
# Kept in the app's directory (SYSTEM and Administrators only), so running
# install.ps1 again after a restart keeps the password it reported.
$SqlPasswordFile = Join-Path $env:PROXMOTION_APP_DIR 'sa-password.txt'

function Test-SqlInstalled {
    return $null -ne (Get-Service -Name $SqlService -ErrorAction SilentlyContinue)
}

# Get-SqlInstanceId: the instance's registry id (MSSQL16.SQLEXPRESS for SQL
# Server 2022), or $null when setup never registered one.
function Get-SqlInstanceId {
    $names = Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Microsoft SQL Server\Instance Names\SQL' -ErrorAction SilentlyContinue

    if ($null -eq $names -or $null -eq $names.PSObject.Properties[$SqlInstance]) {
        return $null
    }

    return [string] $names.$SqlInstance
}

# Assert-Sql2022: fail when the SQLEXPRESS service belongs to another SQL
# Server version or to a setup that did not finish, e.g. the SQL Server 2025
# instance a failed 1.0.0 run left behind; its engine may never start here.
function Assert-Sql2022 {
    $id = Get-SqlInstanceId

    if ($null -eq $id -or -not $id.StartsWith('MSSQL16.')) {
        $found = if ($null -eq $id) { 'one whose setup did not finish' } else { $id }
        Pm-Fail "The SQLEXPRESS instance on this server is not SQL Server 2022 ($found), for example one left by a failed SQL Server 2025 setup. Remove it in Settings > Apps or reinstall the server, then install the app again."
    }
}

# Get-SqlErrorLog: the last lines of the instance's error log, or ''.
function Get-SqlErrorLog {
    $id = Get-SqlInstanceId

    if ($null -eq $id) {
        return ''
    }

    $parameters = Get-ItemProperty -Path "HKLM:\SOFTWARE\Microsoft\Microsoft SQL Server\$id\MSSQLServer\Parameters" -ErrorAction SilentlyContinue

    if ($null -eq $parameters) {
        return ''
    }

    # Setup passes the error log as one of SQLArg0..2: -e<path>.
    $file = $parameters.PSObject.Properties |
        Where-Object { $_.Name -like 'SQLArg*' -and "$($_.Value)".StartsWith('-e') } |
        ForEach-Object { "$($_.Value)".Substring(2) } |
        Select-Object -First 1

    if ($null -eq $file -or -not (Test-Path -LiteralPath $file)) {
        return ''
    }

    return (Get-Content -LiteralPath $file -Tail 20) -join "`n"
}

# Start-SqlService [-Restart]: start (or restart) the instance; when it does
# not start, print the end of its error log and fail.
function Start-SqlService([switch] $Restart) {
    try {
        if ($Restart) {
            Restart-Service -Name $SqlService -Force
        } else {
            Start-Service -Name $SqlService
        }
    } catch {
        $message = $_.Exception.Message
        $log = Get-SqlErrorLog

        if ($log -ne '') {
            Write-Output "Last lines of the SQL Server error log:`n$log"
        }

        Pm-Fail "SQL Server did not start: $message"
    }
}

# Get-SqlAccount SID: a local account's name in this Windows' language.
function Get-SqlAccount([string] $Sid) {
    return (New-Object System.Security.Principal.SecurityIdentifier $Sid).Translate([System.Security.Principal.NTAccount]).Value
}

# Get-SqlPassword [-New]: the stored sa password, or a new one.
function Get-SqlPassword([switch] $New) {
    if (-not $New -and (Test-Path -LiteralPath $SqlPasswordFile)) {
        return ([System.IO.File]::ReadAllText($SqlPasswordFile)).Trim()
    }

    $password = Pm-RandomPassword 24
    Write-PmFile $SqlPasswordFile "$password`n"

    return $password
}

# Invoke-Sql QUERY: run a statement as SYSTEM, a sysadmin since setup.
function Invoke-Sql([string] $Query) {
    $connection = New-Object System.Data.SqlClient.SqlConnection "Server=.\$SqlInstance;Database=master;Integrated Security=True;Connect Timeout=30"

    try {
        $connection.Open()
        $command = $connection.CreateCommand()
        $command.CommandText = $Query
        [void] $command.ExecuteNonQuery()
    } finally {
        $connection.Dispose()
    }
}

# Set-SqlSaPassword PASSWORD: enable sa with this password. ALTER LOGIN takes
# no parameters; the password has letters and digits only (Pm-RandomPassword),
# and quotes are doubled all the same.
function Set-SqlSaPassword([string] $Password) {
    $literal = $Password.Replace("'", "''")
    Invoke-Sql "ALTER LOGIN [sa] WITH PASSWORD = N'$literal'; ALTER LOGIN [sa] ENABLE;"
}

# Wait-SqlService: start the instance and wait until it takes connections.
function Wait-SqlService {
    Start-SqlService

    for ($i = 0; $i -lt 30; $i++) {
        try {
            Invoke-Sql 'SELECT 1'
            return
        } catch {
            Start-Sleep -Seconds 2
        }
    }

    Pm-Fail 'SQL Server did not accept connections; see the SQL Server error log.'
}
