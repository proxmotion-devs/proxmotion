# cPanel & WHM

Installs [cPanel & WHM](https://cpanel.net/) with cPanel's
[official installer](https://docs.cpanel.net/installation-guide/install/) on AlmaLinux 8,
9 or 10, Rocky Linux 8 or 9, or Ubuntu 24.04 (the systems cPanel supports). The install
takes 30 to 60 minutes.

Outputs: the WHM address (`https://<server address>:2087/`) and the user `root`, who logs
in with the server's root password (as set in the billing system), and a notice about the
licence.

- **Licence:** `license: external`. cPanel starts a 15-day trial licence for the server's
  address on its own; the licence itself is sold separately (e.g. as a WHMCS product).
  Proxmotion only installs.
- **Fresh server only:** the recipe refuses a server where a web or database server is
  running, and the installer refuses other conflicting software (its log is
  `/var/log/cpanel-install.log`). On Ubuntu, `ufw` is disabled as cPanel requires.
  CloudLinux conversion is skipped (`--skip-cloudlinux`).
- **Requirements:** at least 2 GB of memory (4 GB recommended) and 20 GB of disk
  (40 GB recommended); a static public IPv4 address, so not offered on NAT VPS. The
  hostname should be a fully qualified domain name that resolves to the server.
- **No uninstall:** cPanel has no uninstaller; reinstall the server to remove it. Running
  the recipe again on a server where cPanel is installed only reports its address.

Not run on a real server yet (`recipes` integration group).
