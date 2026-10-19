# FASTPANEL

Installs the [FASTPANEL](https://fastpanel.direct/) hosting control panel with its
official installer: the panel, nginx and Apache, a database server, FTP and mail.
Supported on Debian 12 and Ubuntu 22.04 and 24.04.

| Input      | Meaning                                                                                      |
| ---------- | -------------------------------------------------------------------------------------------- |
| `database` | `default` (the installer's choice for the OS), `mariadb10.11`, `mariadb11.4` or `percona8.0` |

Outputs: the panel address (`https://<server address>:8888/`), the user `fastuser` and a
generated password.

FASTPANEL can only be installed on a freshly installed server: its installer stops when
nginx, Apache or a database server is already there (its log is
`/tmp/install_fastpanel.debug`). The installer also finishes successfully when its web
server step fails, so the recipe checks for `/usr/sbin/nginx` afterwards and fails with the
last lines of that log (copied into the run's log) when nginx is missing. The panel asks for a free licence key on the first
login. The browser warns about the panel's self-signed certificate until one is issued
in the panel.

Running the recipe again on a server where FASTPANEL is installed only sets a new
password for `fastuser`. There is no uninstall: FASTPANEL changes too much of the system,
so reinstall the server to remove it. Not offered on NAT VPS, where ports 80, 443 and
8888 are not reachable directly.
