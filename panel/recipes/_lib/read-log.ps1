# Prints a byte range of a file as base64 (docs/adr/0021, 0029): the panel
# copies run.log in pieces through the guest agent. The file, offset and
# length are arguments, never part of a command string.
#
#   read-log.ps1 FILE OFFSET COUNT
param(
    [Parameter(Mandatory = $true)] [string] $File,
    [Parameter(Mandatory = $true)] [long] $Offset,
    [Parameter(Mandatory = $true)] [int] $Count
)

$ErrorActionPreference = 'Stop'

# Shared, so the runner keeps appending while this reads.
$stream = [System.IO.File]::Open($File, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)

try {
    [void] $stream.Seek($Offset, [System.IO.SeekOrigin]::Begin)
    $buffer = New-Object byte[] $Count
    $read = 0

    while ($read -lt $Count) {
        $n = $stream.Read($buffer, $read, $Count - $read)

        if ($n -eq 0) {
            break
        }

        $read += $n
    }

    [Console]::Out.Write([System.Convert]::ToBase64String($buffer, 0, $read))
} finally {
    $stream.Dispose()
}
