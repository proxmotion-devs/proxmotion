# WHMCS

Installs [WHMCS](https://www.whmcs.com/) 9 on nginx, PHP-FPM with the
[ionCube Loader](https://www.ioncube.com/loaders.php) and MariaDB, on Debian 12 (PHP
8.2) or Ubuntu 24.04 (PHP 8.3), the releases whose PHP WHMCS 9 supports. WHMCS is
installed with its command-line installer (`install/bin/installer.php -i -n -c`).

WHMCS cannot be downloaded without an account, so the customer brings their own copy:

| Input          | Meaning                                                                                                     |
| -------------- | ----------------------------------------------------------------------------------------------------------- |
| `download_url` | HTTPS address of the WHMCS full release zip (from the WHMCS members area or the licence reseller)           |
| `license_key`  | The WHMCS licence key (a password input: never shown again or logged)                                       |
| `accept_eula`  | Must be ticked: the installer accepts the WHMCS End User Licence Agreement on the customer's behalf         |
| `admin_user`   | Administrator username (`whmcsadmin` by default)                                                            |
| `domain`       | Optional domain for the site; without one WHMCS answers on the server's address                             |
| `letsencrypt`  | Get a Let's Encrypt certificate for the domain (accepts the Let's Encrypt terms), with the optional `email` |

Outputs: the client area (`/`) and administration (`/admin/`) addresses, the
administrator's username and generated password, and a notice. WHMCS's cron runs every
five minutes from `/etc/cron.d/proxmotion-whmcs`; the system URL is set to the site's
address. The `install` directory is deleted afterwards and `configuration.php` is
readable only by root and the web server.

- **Licence:** `license: external`. The licence is bought from WHMCS or a reseller;
  Proxmotion only installs. Download links from the members area expire: a failed
  download asks for a new link.
- **Running it again** after a failed attempt starts over (files and database of the
  attempt are removed); on a finished installation it refuses to run.
- **Uninstall** removes the files, the database, the cron job, the nginx site and the
  certificate; nginx, PHP, the ionCube Loader and MariaDB stay.
- Without a domain, WHMCS takes the server's address and cannot share it with the
  WordPress app; give one of them a domain.
- Not offered on NAT VPS, where ports 80 and 443 are not reachable directly. x86_64 only
  (the ionCube Loader).

Not run on a real server yet (`recipes` integration group). Unverified: the installer's
JSON configuration with a WHMCS 9 release, and the `tblconfiguration` row the system URL
is written to.
