# Plesk

Installs [Plesk Obsidian](https://www.plesk.com/) with Plesk's one-click installer
(the latest stable release with its recommended components) on Debian 12 or later,
Ubuntu 22.04 or later, AlmaLinux 8 or later, or Rocky Linux 8. The install takes 30 to
60 minutes.

Outputs: the panel address (`https://<server address>:8443/`), the user `admin` with a
generated password, and a notice. On the first login Plesk asks for the administrator's
contact details and the licence agreement and offers a trial licence; the recipe accepts
nothing on the customer's behalf.

- **Licence:** `license: external`. The licence is sold separately (e.g. as a WHMCS
  product); Proxmotion only installs.
- **Fresh server only:** the recipe refuses a server where a web server is running, and
  the installer refuses other conflicting software (its log is
  `/var/log/plesk/install/autoinstaller3.log`).
- **Requirements:** at least 2 GB of memory (Plesk names 1 GB plus 1 GB of swap as its
  minimum) and 20 GB of disk. Not offered on NAT VPS, where ports 80, 443 and 8443 are
  not reachable directly.
- **Action:** `reset_password` sets a new password for `admin`. Running the recipe again
  on a server where Plesk is installed does the same.
- **No uninstall:** reinstall the server to remove Plesk.

Not run on a real server yet (`recipes` integration group).
