# aaPanel

Installs the free [aaPanel](https://www.aapanel.com/) through its official installer
(`install_7.0_en.sh`) on Debian 12, Ubuntu 22.04 or 24.04, AlmaLinux 8 or 9, or Rocky Linux
8 or 9. The installer only sets up the panel, which takes 5 to 15 minutes; web servers
(nginx, Apache, OpenLiteSpeed), PHP, databases and FTP are chosen in the panel after the
first login.

Outputs: the panel address, the username and password the installer generated, and a
notice.

- **Address:** the installer picks a random port and a security path (e.g.
  `https://<server address>:12345/abcd1234`) and prints them with the login; the recipe
  reads them from the installer's output and shows the server's own address instead of the
  public address aaPanel looks up online. When they cannot be read, the notice says to run
  `bt default` on the server.
- **Certificate:** the panel uses a self-signed certificate.
- **Fresh server only:** the recipe refuses a server where `/www` exists or a web or
  database server is running.
- **Running again** on a server where aaPanel is installed shows `bt default`'s address
  and username; aaPanel shows the password only until it is changed.
- **Password:** there is no reset action, since aaPanel only offers that in its
  interactive `bt` menu (option 5).
- **No uninstall:** reinstall the server to remove aaPanel.
- Not offered on NAT VPS, where the panel's random port and the web ports are not
  reachable directly.

Not run on a real server yet (`recipes` integration group). Still to verify: the
installer's single question on standard input and the lines the login is read from.
