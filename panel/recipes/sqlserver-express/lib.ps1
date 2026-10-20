# Shared by the SQL Server Express scripts.

$SqlInstance = 'SQLEXPRESS'
$SqlService = 'MSSQL$SQLEXPRESS'
# Kept in the app's directory (SYSTEM and Administrators only), so running
# install.ps1 again after a restart keeps the password it reported.
$SqlPasswordFile = Join-Path $env:PROXMOTION_APP_DIR 'sa-password.txt'

function Test-SqlInstalled {
    return $null -ne (Get-Service -Name $SqlService -ErrorAction SilentlyContinue)
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
    Start-Service -Name $SqlService

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
