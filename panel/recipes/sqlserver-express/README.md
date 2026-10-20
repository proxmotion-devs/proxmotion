# SQL Server Express

Installs [Microsoft SQL Server 2022 Express](https://www.microsoft.com/sql-server/sql-server-downloads)
as the instance `SQLEXPRESS` with Microsoft's installer: the Express bootstrapper
(`go.microsoft.com/fwlink/p/?linkid=2216019`) downloads the Core package, which is
unpacked and installed with `setup.exe /Q /ACTION=Install /FEATURES=SQLEngine`. SQL Server
2022 runs on Windows Server 2016 and later and on Windows 10 and 11, so the app should not
be offered on older releases.

Outputs: the server (`<server address>,<port>`), the user `sa`, its generated password and
a notice.

- **Logins:** mixed authentication. `sa` gets a 24-character password of letters and
  digits, kept in the app's directory (SYSTEM and Administrators only) so running the
  script again reports the same one. The local `SYSTEM` account and the `Administrators`
  group are sysadmins.
- **Port:** the `port` input (default 1433) is set as the instance's fixed TCP port
  (dynamic ports off), with an inbound firewall rule named after the installation.
- **Running again** keeps the instance, its databases and the password, and applies the
  port again.
- **Action:** `reset_password` gives `sa` a new password.
- **Uninstall** removes the instance with SQL Server's setup and the firewall rule.
  Database files in the instance's `DATA` folder stay on disk.
- Express limits: 10 GB per database, at most 1410 MB of buffer pool memory.
- Not offered on NAT VPS: the address shown would be the server's own port, not the
  forwarded one.

Not run on a real server yet.
