# FASTPANEL

Installs the [FASTPANEL](https://fastpanel.direct/) hosting control panel with its
official installer: the panel, nginx and Apache, a database server, FTP and mail.
Supported on Debian 12 and Ubuntu 22.04 and 24.04.

| Input      | Meaning                                                                                                                                                                                                                                                                    |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `database` | `default` (the installer's choice: Percona Server 8.0 on Debian 12, the operating system's own server on Ubuntu), `distribution` (the operating system's own server, e.g. MariaDB 10.11 on Debian 12), `mariadb11.4`, `mariadb11.8` (MariaDB's repository) or `percona8.0` |

MariaDB 10.11 is not offered as its own choice: FASTPANEL installs it from MariaDB's
legacy repository (`downloads.mariadb.com`), which no longer receives updates and
whose package downloads can be redirected to a web page, failing the install. Choose
`distribution` on Debian 12 for MariaDB 10.11.

Outputs: the panel address (`https://<server address>:8888/`), the user `fastuser` and a
generated password.

FASTPANEL can only be installed on a freshly installed server: its installer stops when
nginx, Apache or a database server is already there (its log is
`/tmp/install_fastpanel.debug`). The installer's exit status cannot be trusted (its
downloader exits 0 even when the installer failed), so the recipe fails when `fastpanel2`
or `/usr/sbin/nginx` is missing or the installer printed its failure message, with the
first error of that log as the reason and its last lines in the run's log. When a
download fails (e.g. from the MariaDB repository), try again later or choose another
database server; reinstall the server first. The panel asks for a free licence key on
the first login. The browser warns about the panel's self-signed certificate until one is issued
in the panel.

Running the recipe again on a server where FASTPANEL is installed only sets a new
password for `fastuser`. There is no uninstall: FASTPANEL changes too much of the system,
so reinstall the server to remove it. Not offered on NAT VPS, where ports 80, 443 and
8888 are not reachable directly.
