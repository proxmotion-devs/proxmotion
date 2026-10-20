# Removes IIS (and with it ASP.NET 4.x and the IIS Manager) and the firewall
# rule. The sites' files in inetpub and the ASP.NET Core Hosting Bundle stay.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0
. "$env:PROXMOTION_APP_DIR\proxmotion.ps1"

Pm-Step 20 'Closing HTTP and HTTPS'
Pm-RemoveFirewallPort 'web'

Pm-Step 40 'Removing IIS'
$restart = Pm-RemoveFeature -Server @('Web-Server') -Desktop @('IIS-WebServerRole')

if ($restart) {
    Pm-RequestRestart
}
