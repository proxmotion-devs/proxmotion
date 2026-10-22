# Shared by the Chocolatey scripts.

$ChocoRoot = Join-Path $env:ProgramData 'chocolatey'
$Choco = Join-Path $ChocoRoot 'bin\choco.exe'

function Test-Choco {
    return Test-Path -LiteralPath $Choco
}

# Get-ChocoPackage KEY: a package id from an input. The panel only checks its
# length, so anything but a Chocolatey id is refused before choco sees it;
# it is passed as one argument, never inside a command string.
function Get-ChocoPackage([string] $Key) {
    $package = Pm-Input $Key

    if ($package -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$') {
        Pm-Fail "Not a Chocolatey package id: $package"
    }

    return $package
}

# Exit codes choco reports for success, also when Windows needs a restart.
$ChocoSuccess = @(0, 1605, 1614, 1641, 3010)
