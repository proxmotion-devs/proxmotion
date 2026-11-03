# Proxmotion

**Open-source VPS management panel for Proxmox VE** — in the spirit of Virtualizor and VirtFusion.

> ⚠️ **Status: early development (Phase 0).** Not ready for production use.

Proxmotion lets hosting providers connect their Proxmox nodes and clusters, and sell VPS through billing systems such as WHMCS and WISECP. Customers manage their servers in the billing system's client area through a Proxmotion module — there is no separate client panel.

## Planned features

- **Mixed infrastructure:** standalone Proxmox nodes and clusters side by side
- **Automatic provisioning** from cloud-init templates, triggered by your billing system
- **Network models:** bridged public IPs, routed/failover IPs, NAT VPS, IPv6
- **Customer actions in your billing system:** power actions, browser console (noVNC), reinstall, root password, rDNS, graphs — through the API and module
- **App Marketplace:** one-click OpenVPN, WireGuard, Docker, control panels and web stacks — at order time or later
- **Billing integration:** REST API + WHMCS and WISECP modules (Blesta, HostBill, Paymenter, FOSSBilling later)
- **Migration:** adopt existing Proxmox VMs, import from Virtualizor
- Snapshots, backups, traffic accounting and quotas, audit log, 2FA
- English and Turkish UI

See the [feature overview](docs/wiki/Features.md) for what Proxmotion can do today, and [AGENTS.md](AGENTS.md) for the full architecture and [the roadmap](AGENTS.md#10-roadmap).

## Tech stack

Laravel 13 · PHP 8.3 · Vue 3 + Inertia.js 3 + TypeScript · Tailwind CSS 4 · Vite+ · MariaDB · Redis · Reverb

## Development

Requirements: PHP 8.3+, Composer 2, Node.js 22+, MariaDB/MySQL (or SQLite for quick local work), Redis. On Windows, [Laravel Herd](https://herd.laravel.com/windows) is the easiest way to get PHP and Composer.

```bash
cd panel
composer setup
php artisan db:seed
composer dev
```

The seeder creates a local administrator, `admin@example.com`, with the password `password`. Never run it in production. Panel accounts are staff only; customers live in your billing system.

Administrators must set up two-factor authentication (TOTP) before they can open the admin panel. For local development only, you can turn this off with `PROXMOTION_REQUIRE_ADMIN_2FA=false` in `.env`.

Run all checks (the same ones CI runs) with `composer ci:check`.

Or with Docker:

```bash
docker compose -f deploy/docker/compose.yml up -d
```

## Production

With Docker Compose, from the images published to GHCR: follow [docs/guides/docker-install.md](docs/guides/docker-install.md) ([ADR 0054](docs/adr/0054-production-docker-images.md)). Directly on Debian 12/13 or Ubuntu 22.04/24.04: follow [docs/guides/native-install.md](docs/guides/native-install.md) ([ADR 0055](docs/adr/0055-native-installer.md)). Both run the same commands, which you can also run by hand inside `panel/` once the code, `.env` and the dependencies are in place:

```bash
php artisan proxmotion:install   # checks requirements, migrates, creates the first administrator
php artisan proxmotion:upgrade   # after updating the code: migrations in maintenance mode, worker restarts
```

See [ADR 0052](docs/adr/0052-install-and-upgrade-commands.md) for what each step does. The panel's name, colour, logo and support contacts are set under *Panel settings → Branding* ([ADR 0053](docs/adr/0053-branding.md)).

## Contributing

Contributions are welcome — please read [CONTRIBUTING.md](CONTRIBUTING.md) and our [Code of Conduct](CODE_OF_CONDUCT.md). To report a security issue, follow [SECURITY.md](SECURITY.md); do not open a public issue.

## License

Proxmotion is licensed under the [GNU Affero General Public License v3.0](LICENSE). If you run a modified version as a network service, you must make your modified source code available to its users.

Proxmox® is a registered trademark of Proxmox Server Solutions GmbH. This project is not affiliated with or endorsed by Proxmox Server Solutions GmbH, WHMCS, Virtualizor or VirtFusion.
