# Proxmotion: the additional IP addresses of this server (docs/adr/0010 in the
# Proxmotion repository). Installed and run by Proxmotion through the QEMU
# guest agent; changes to this file are overwritten.
#
# C:\ProgramData\Proxmotion\addresses.json (written by Proxmotion):
#   { "mac": "52:54:00:12:34:56", "addresses": ["203.0.113.10/24", ...] }
#
# Adds the listed addresses (persistent, so they survive a reboot), removes
# those this script added earlier and that are no longer listed (never
# others). Safe to run any number of times.
$ErrorActionPreference = 'Stop'

$dir = 'C:\ProgramData\Proxmotion'
$state = Join-Path $dir 'addresses.applied'
$config = Get-Content -Raw -Path (Join-Path $dir 'addresses.json') | ConvertFrom-Json

$mac = ([string]$config.mac).Replace(':', '-').ToUpperInvariant()
$adapter = Get-NetAdapter | Where-Object { $_.MacAddress -eq $mac } | Select-Object -First 1

if (-not $adapter) {
    [Console]::Error.WriteLine("No network adapter with MAC address $($config.mac).")
    exit 2
}

# Only well-formed addresses with a prefix are used.
function Split-Address([string]$entry) {
    $parts = $entry.Split('/')
    $ip = $null
    $prefix = 0

    if ($parts.Count -ne 2 -or -not [System.Net.IPAddress]::TryParse($parts[0], [ref]$ip) -or -not [int]::TryParse($parts[1], [ref]$prefix)) {
        return $null
    }

    return @{ Address = $ip.ToString(); Prefix = $prefix }
}

$wanted = @($config.addresses | ForEach-Object { [string]$_ })
$applied = @()

if (Test-Path $state) {
    $applied = @(Get-Content -Path $state | Where-Object { $_ })
}

foreach ($entry in $applied) {
    $address = Split-Address $entry

    if ($address -and $wanted -notcontains $entry) {
        Remove-NetIPAddress -InterfaceIndex $adapter.ifIndex -IPAddress $address.Address -Confirm:$false -ErrorAction SilentlyContinue
    }
}

foreach ($entry in $wanted) {
    $address = Split-Address $entry

    if ($address -and -not (Get-NetIPAddress -InterfaceIndex $adapter.ifIndex -IPAddress $address.Address -ErrorAction SilentlyContinue)) {
        New-NetIPAddress -InterfaceIndex $adapter.ifIndex -IPAddress $address.Address -PrefixLength $address.Prefix -SkipAsSource $true | Out-Null
    }
}

Set-Content -Path $state -Value $wanted -Encoding ASCII
