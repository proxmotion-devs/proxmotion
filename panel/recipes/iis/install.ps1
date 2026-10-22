# IIS with ASP.NET 4.x and the IIS Manager: Windows features on Windows
# Server, optional features on Windows 10/11. Optionally the ASP.NET Core
# Hosting Bundle from Microsoft's download link. Running it again changes
# nothing that is already there.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0
. "$env:PROXMOTION_APP_DIR\proxmotion.ps1"

Pm-Step 10 'Turning on IIS and ASP.NET'
$restart = Pm-Feature `
    -Server @('Web-Server', 'Web-Asp-Net45', 'Web-Mgmt-Console') `
    -Desktop @('IIS-WebServerRole', 'IIS-WebServer', 'IIS-ASPNET45', 'IIS-ManagementConsole')

if ($restart) {
    Pm-RequestRestart
}

# An enum input: none, 8.0 or 10.0, checked by the panel.
$core = Pm-Input 'aspnet_core'

if ($core -ne '' -and $core -ne 'none') {
    Pm-Step 50 "Installing the ASP.NET Core $core Hosting Bundle"
    $installer = Join-Path $env:PROXMOTION_APP_DIR "dotnet-hosting-$core.exe"
    Pm-Download "https://aka.ms/dotnet/$core/dotnet-hosting-win.exe" $installer
    # 1638: this or a newer version is installed already.
    Pm-Exec $installer @('/install', '/quiet', '/norestart') -SuccessCodes 0, 1638, 3010
    Remove-Item -LiteralPath $installer -Force

    # The bundle's documentation: restart IIS so it picks up the new PATH.
    Pm-Exec 'net.exe' @('stop', 'was', '/y') -SuccessCodes 0, 2
    Pm-Exec 'net.exe' @('start', 'w3svc') -SuccessCodes 0, 2
}

Pm-Step 80 'Opening HTTP and HTTPS'
Pm-FirewallPort 'web' 'TCP' @('80', '443')
Start-Service -Name 'W3SVC'

Pm-Output 'site_url' "http://$(Pm-PublicAddress)/"
Pm-Output 'web_root' (Join-Path $env:SystemDrive 'inetpub\wwwroot')
