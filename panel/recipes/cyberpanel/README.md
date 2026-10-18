# CyberPanel

Installs [CyberPanel](https://cyberpanel.net/) with OpenLiteSpeed through its official
installer on Ubuntu 22.04, 24.04 or 26.04, AlmaLinux 8 to 10 or Rocky Linux 8 (x86_64
only): OpenLiteSpeed, MariaDB, and Postfix, PowerDNS and Pure-FTPd unless the `services`
input is off. The `addons` input adds Memcached and Redis with their PHP extensions. The
install takes 20 to 60 minutes.

Outputs: the panel address (`https://<server address>:8090/`), the user `admin` with a
generated password, and a notice.

- **Edition:** always OpenLiteSpeed (free). LiteSpeed Enterprise needs a licence key
  from LiteSpeed and is not offered.
- **Password:** the installer gets a throwaway password; the real one is set afterwards
  with CyberPanel's `adminPass`.
- **Fresh server only:** the recipe refuses a server where a web or database server is
  running. The installer exits with status 0 on many errors, so the recipe checks that
  CyberPanel is there afterwards (its log is `/var/log/installLogs.txt`); a half-finished
  install needs a reinstall of the server.
- **Restart:** CyberPanel recommends one restart after the install; the recipe does not
  restart the server on its own.
- **Requirements:** at least 1 GB of memory and 10 GB of disk. Not offered on NAT VPS,
  where the web, mail and DNS ports are not reachable directly.
- **Action:** `reset_password` sets a new password for `admin`. Running the recipe again
  on a server where CyberPanel is installed does the same.
- **No uninstall:** reinstall the server to remove CyberPanel.

Not run on a real server yet (`recipes` integration group).
