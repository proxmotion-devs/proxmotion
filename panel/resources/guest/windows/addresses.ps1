# Proxmotion: the additional IP addresses of this server and a primary address
# that differs from the one cloudbase-init configured (docs/adr/0010 and 0011
# in the Proxmotion repository). Installed and run by Proxmotion through the
# QEMU guest agent; changes to this file are overwritten.
#
# C:\ProgramData\Proxmotion\addresses.json (written by Proxmotion):
#   { "mac": "52:54:00:12:34:56", "addresses": ["203.0.113.11/24", ...],
#     "primary_ipv4": "203.0.113.12/24", "gateway_ipv4": "203.0.113.1",
#     "cloudinit_ipv4": "203.0.113.10/24", "primary_ipv6": ..., ... }
#
# Adds the listed addresses (persistent, so they survive a reboot), removes
# those this script added earlier and that are no longer listed (never
# others). When the primary differs from the configured address, adds it,
# routes the default route through its gateway and removes the configured
# address unless it is still listed. Registers a scheduled task that runs it
# again at every startup. Safe to run any number of times.
$ErrorActionPreference = 'Stop'

$dir = 'C:\ProgramData\Proxmotion'
$state = Join-Path $dir 'addresses.applied'
$config = Get-Content -Raw -Path (Join-Path $dir 'addresses.json') | ConvertFrom-Json

$mac = ([string]$config.mac).Replace(':', '-').ToUpperInvariant()

# Run again at every startup (like the systemd unit on Linux), so the
# addresses come back even if this run fails or Windows restarts during setup.
$taskName = 'Proxmotion addresses'
try {
    if (-not (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue)) {
        $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$dir\addresses.ps1`""
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger (New-ScheduledTaskTrigger -AtStartup) -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
    }
} catch {
    # Task Scheduler not ready yet: the next run registers it.
}

# Right after an install the guest agent answers while Windows is still setting
# itself up: cloudbase-init may still rewrite the adapter's addresses, and the
# network cmdlets may fail ("Critical error"). Wait for both, about 4 minutes.
$deadline = (Get-Date).AddMinutes(4)

while ((Get-Service -Name 'cloudbase-init' -ErrorAction SilentlyContinue).Status -eq 'Running' -and (Get-Date) -lt $deadline) {
    Start-Sleep -Seconds 5
}

$adapter = $null

while ($true) {
    try {
        $adapter = Get-NetAdapter -ErrorAction Stop | Where-Object { $_.MacAddress -eq $mac } | Select-Object -First 1
    } catch {
        $adapter = $null
    }

    if ($adapter -or (Get-Date) -ge $deadline) {
        break
    }

    Start-Sleep -Seconds 5
}

if (-not $adapter) {
    [Console]::Error.WriteLine("No network adapter with MAC address $($config.mac).")
    exit 2
}

$index = $adapter.ifIndex

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

function Has-Address([string]$ip) {
    return [bool](Get-NetIPAddress -InterfaceIndex $index -IPAddress $ip -ErrorAction SilentlyContinue)
}

$wanted = @($config.addresses | ForEach-Object { [string]$_ })
$primaries = @([string]$config.primary_ipv4, [string]$config.primary_ipv6)
$applied = @()

if (Test-Path $state) {
    $applied = @(Get-Content -Path $state | Where-Object { $_ })
}

foreach ($entry in $applied) {
    $address = Split-Address $entry

    if ($address -and $wanted -notcontains $entry -and $primaries -notcontains $entry) {
        Remove-NetIPAddress -InterfaceIndex $index -IPAddress $address.Address -Confirm:$false -ErrorAction SilentlyContinue
    }
}

foreach ($entry in $wanted) {
    $address = Split-Address $entry

    if ($address -and -not (Has-Address $address.Address)) {
        New-NetIPAddress -InterfaceIndex $index -IPAddress $address.Address -PrefixLength $address.Prefix -SkipAsSource $true | Out-Null
    } elseif ($address) {
        Set-NetIPAddress -InterfaceIndex $index -IPAddress $address.Address -SkipAsSource $true
    }
}

Set-Content -Path $state -Value $wanted -Encoding ASCII

# The primary address of one IP version, when it is not the configured one:
# the cloud-init settings of a running server are never changed (ADR 0011).
function Set-Primary([string]$family) {
    $text = [string]$config."primary_ipv$family"
    $primary = Split-Address $text

    if (-not $primary) {
        return
    }

    $cloud = [string]$config."cloudinit_ipv$family"
    $gateway = [string]$config."gateway_ipv$family"
    $file = Join-Path $dir "primary-ipv$family.applied"
    $previous = ''
    $default = if ($family -eq '4') { '0.0.0.0/0' } else { '::/0' }

    if (Test-Path $file) {
        $previous = (Get-Content -Raw -Path $file).Trim()
    }

    # The same address (the configuration may know it with another prefix).
    $cloudAddress = Split-Address $cloud
    $isCloud = $cloudAddress -and $cloudAddress.Address -eq $primary.Address

    if ($isCloud -and -not $previous) {
        return
    }

    if (Has-Address $primary.Address) {
        Set-NetIPAddress -InterfaceIndex $index -IPAddress $primary.Address -SkipAsSource $false
    } else {
        New-NetIPAddress -InterfaceIndex $index -IPAddress $primary.Address -PrefixLength $primary.Prefix | Out-Null
    }

    $gatewayIp = $null
    if ([System.Net.IPAddress]::TryParse($gateway, [ref]$gatewayIp)) {
        Get-NetRoute -InterfaceIndex $index -DestinationPrefix $default -ErrorAction SilentlyContinue | Remove-NetRoute -Confirm:$false
        New-NetRoute -InterfaceIndex $index -DestinationPrefix $default -NextHop $gatewayIp.ToString() | Out-Null
    }

    foreach ($old in @($cloud, $previous)) {
        $address = Split-Address $old

        if ($address -and $address.Address -ne $primary.Address -and $wanted -notcontains $old) {
            Remove-NetIPAddress -InterfaceIndex $index -IPAddress $address.Address -Confirm:$false -ErrorAction SilentlyContinue
        }
    }

    if ($isCloud) {
        Remove-Item -Path $file
    } else {
        Set-Content -Path $file -Value $text -Encoding ASCII
    }
}

Set-Primary '4'
Set-Primary '6'
