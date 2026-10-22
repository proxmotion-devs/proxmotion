# IIS

Turns on Internet Information Services with ASP.NET 4.x and the IIS Manager: the Windows
features `Web-Server`, `Web-Asp-Net45` and `Web-Mgmt-Console` on Windows Server, the
optional features `IIS-WebServerRole`, `IIS-WebServer`, `IIS-ASPNET45` and
`IIS-ManagementConsole` on Windows 10 and 11. Written for Windows Server 2016 to 2025 and
Windows 10/11 (the runner needs Windows PowerShell 5.1, docs/adr/0029).

Outputs: the default site's address (`http://<server address>/`) and its folder
(`C:\inetpub\wwwroot`).

- **ASP.NET Core:** the `aspnet_core` input (`none`, `8.0` or `10.0`) installs the
  [ASP.NET Core Hosting Bundle](https://learn.microsoft.com/aspnet/core/host-and-deploy/iis/hosting-bundle)
  of that release from Microsoft's `aka.ms/dotnet/<release>/dotnet-hosting-win.exe` link
  (its newest patch) and restarts IIS.
- **Firewall:** an inbound rule for TCP 80 and 443 named after the installation.
- **Restarts:** when Windows asks for one after turning on the features, the runner
  restarts the server and runs the script again.
- **Running again** keeps everything that is there.
- **Uninstall** removes IIS with ASP.NET and the IIS Manager and the firewall rule. The
  files in `C:\inetpub` and the Hosting Bundle stay.
- Not offered on NAT VPS: the address shown would be the server's own port, not the
  forwarded one.

Not run on a real server yet.
