# HestiaCP

Installs the [Hestia Control Panel](https://hestiacp.com/) with its official installer on
Debian 12 or 13 or Ubuntu 22.04, 24.04 or 26.04: nginx (with Apache behind it unless the
`apache` input is off), PHP-FPM, MariaDB, BIND, vsftpd, iptables with Fail2Ban, and
Exim, Dovecot and SpamAssassin unless the `mail` input is off. ClamAV is left out (it
needs several GB of memory; it can be added later in the panel). The install takes 10 to
30 minutes.

Inputs: `hostname` (the panel's fully qualified hostname, e.g. `panel.example.com`;
defaults to the server's hostname), `email` (defaults to `admin@<hostname>`; the
installer mails a welcome message there), `apache`, `mail` and `language`.

Outputs: the panel address (`https://<server address>:8083/`), the user `admin` with a
generated password, and a notice.

- **Hostname:** HestiaCP needs a hostname with at least two dots and sets the server's
  hostname to it (Proxmotion's record of the hostname does not follow). The recipe fails
  before installing anything when neither the input nor the server's hostname is one.
- **Password:** the installer gets a throwaway password; the real one is set
  afterwards, so it is neither in the installer's log nor in its email.
- **Fresh server only:** the recipe refuses a server where a web, database or mail
  server is running or a user or group `admin` exists, and the installer refuses other
  conflicting packages. It is never run with `--force`, which would remove them. The
  installer's log is under `/root/hst_install_backups/`.
- **Restart:** HestiaCP asks for one restart after the install; the recipe does not
  restart the server on its own.
- **Requirements:** at least 1 GB of memory and 10 GB of disk. Not offered on NAT VPS,
  where the web, mail and DNS ports are not reachable directly.
- **Action:** `reset_password` sets a new password for `admin`. Running the recipe again
  on a server where HestiaCP is installed does the same.
- **No uninstall:** reinstall the server to remove HestiaCP.

Not run on a real server yet (`recipes` integration group).
