# WordPress

Installs [WordPress](https://wordpress.org/) with nginx, PHP-FPM and MariaDB from the
distribution's packages and sets it up with [WP-CLI](https://wp-cli.org/) (its download
is checked against the published SHA-512). Supported on Debian 12 and later and Ubuntu
22.04 and later.

| Input         | Meaning                                                                                             |
| ------------- | --------------------------------------------------------------------------------------------------- |
| `admin_email` | Email of the WordPress administrator (required)                                                     |
| `admin_user`  | Username of the administrator (default `admin`)                                                     |
| `site_title`  | Title of the site (default `WordPress`)                                                             |
| `language`    | WordPress language: `en_US`, `tr_TR`, `de_DE`, `fr_FR`, `es_ES`, `it_IT`, `nl_NL`, `pt_BR`, `ru_RU` |
| `domain`      | Domain of the site; without one, the site answers on the server's address                           |
| `letsencrypt` | Get a Let's Encrypt certificate for `domain` with certbot (accepts the Let's Encrypt terms)         |

Outputs: the site and administration addresses, the administrator's username and a
generated password, and a notice when no certificate could be obtained (the domain must
already point to the server; the site then stays on HTTP until the app is run again).

The site lives in `/var/www/wordpress`, its database and database user are both called
`wordpress`, and the nginx site is `proxmotion-wordpress` (it replaces nginx's default
site). The installer stops when Apache is running, since nginx needs ports 80 and 443.

Running the recipe again keeps the site and its database, applies a changed domain or
certificate request, and sets a new administrator password. The action _Reset the
administrator password_ only sets a new password.

Uninstalling deletes the site's files, database, nginx site and certificate. nginx, PHP
and MariaDB stay installed. Not offered on NAT VPS, where ports 80 and 443 are not
reachable directly.
