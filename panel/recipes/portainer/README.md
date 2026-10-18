# Docker + Portainer

Installs Docker Engine from Docker's apt repository (unless Docker is already there) and
[Portainer Community Edition](https://www.portainer.io/) as a container
(`portainer/portainer-ce:lts`) on Debian 12+ or Ubuntu 22.04+. The administrator `admin`
is created on first start with a generated password.

Outputs: the Portainer address (`https://<server address>:<port>/`), the user `admin`, its
password and a notice.

- **Port:** the `port` input (default 9443) is Portainer's HTTPS port; Portainer uses a
  self-signed certificate. The Edge agent port 8000 is not published.
- **Data:** Portainer keeps its database in the Docker volume `portainer_data`.
- **Running again** keeps the container and its data and gives the administrator a new
  password. A changed `port` is not applied to an existing container.
- **Action:** `reset_password` uses Portainer's `portainer/helper-reset-password`, which
  stops Portainer for a few seconds. It resets the first administrator (user id 1), even
  when it was renamed.
- **Uninstall** removes the container, its image and the `portainer_data` volume. Docker
  Engine and other containers stay.
- Not offered on NAT VPS: the address shown would be the server's own port, not the
  forwarded one.

Not run on a real server yet (`recipes` integration group).
