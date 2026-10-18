# Node.js

Installs a long-term support release line of [Node.js](https://nodejs.org/) (24 by
default, or 22) with npm from NodeSource's apt repository on Debian 12+ or Ubuntu 22.04+,
and optionally [PM2](https://pm2.keymetrics.io/) to keep applications running and start
them at boot.

Outputs: the Node.js and npm versions and a notice.

- **Repository:** the recipe adds NodeSource's signing key and repository
  (`https://deb.nodesource.com/node_<line>.x nodistro main`) itself rather than piping
  NodeSource's setup script into a shell, and pins `nodejs` to NodeSource so that the
  distribution's package is never picked instead.
- **PM2:** installed globally with npm and started at boot as `pm2-root`. Start an app with
  `pm2 start app.js`, then `pm2 save`.
- **Running again** updates Node.js within the chosen line, or moves to a newer line.
  Going back to an older line is refused (apt does not downgrade); reinstall the server.
- **No web server:** apps listen on their own ports. Put a reverse proxy (for example the
  LEMP app's nginx) in front of them for a domain and HTTPS.
- **NAT VPS:** offered, since the recipe opens no ports itself.
- **Uninstall** removes PM2 with its boot service, Node.js, npm and the repository.
  Applications and their `node_modules` directories stay.

Not run on a real server yet (`recipes` integration group).
