# Coolify

Installs [Coolify](https://coolify.io/) through its official installer
(`https://cdn.coollabs.io/coolify/install.sh`) on Debian 12+ or Ubuntu 22.04+. The installer
sets up Docker and runs Coolify's containers under `/data/coolify`; it takes 5 to 15
minutes.

The first user is created by the installer from the `ROOT_USERNAME`, `ROOT_USER_EMAIL` and
`ROOT_USER_PASSWORD` variables (the `username` and `email` inputs and a generated
password), so nobody can register first on a fresh dashboard.

Outputs: the dashboard address (`http://<server address>:8000/`), the login email, the
password and a notice.

- **Ports:** 8000 (dashboard), 6001 and 6002 (realtime), 80 and 443 (Coolify's proxy for
  deployed applications). The recipe refuses a server where Apache or nginx is running.
- **HTTPS:** the dashboard starts on plain HTTP; give Coolify a domain under _Settings_ to
  get a certificate.
- **Requirements:** Coolify asks for at least 2 vCPU, 2 GB of memory and 30 GB of disk.
  Not offered on NAT VPS.
- **Running again** on a server where Coolify is installed changes nothing and shows the
  address; the password is not reset (use _Forgot password_ on the login page).
- **No uninstall:** Coolify keeps applications, databases and backups under
  `/data/coolify` and in Docker volumes; reinstall the server to remove it.

Not run on a real server yet (`recipes` integration group). Still to verify: that the
installer creates the first user from the `ROOT_*` variables and accepts the generated
password.
