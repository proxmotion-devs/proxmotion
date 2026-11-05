# Proxmotion

**Open-source VPS management panel for Proxmox VE** — in the spirit of Virtualizor and VirtFusion.

> ⚠️ **Status: 0.1.2, not ready for production.** Phases 0–7 are largely built and covered by automated tests that fake Proxmox, WHMCS and WISECP. Only part of it has been checked against a real Proxmox VE server so far; see [what is verified](docs/wiki/Features.md#17-verification-status) before you rely on anything.

Proxmotion lets hosting providers connect their Proxmox nodes and clusters and sell VPS through billing systems such as WHMCS and WISECP. When a customer orders a VPS, it is provisioned automatically, and the customer manages it in the billing system's client area through a Proxmotion module. There is no separate client panel and there are no customer accounts in Proxmotion.

## Features

- **Mixed infrastructure:** standalone Proxmox nodes and clusters side by side, synced periodically, with storage, ZFS, Ceph and disk health and drift reports
- **Placement:** placement groups per location, over-commit ratios, nodes in maintenance; atomic VMID and IP reservation
- **Automatic provisioning** from cloud-init templates (Linux, Windows with Cloudbase-Init, FreeBSD), with checks from inside the server once it is up
- **Networking:** bridged and routed IPv4, IPv6, IP pools, rDNS through PowerDNS, anti-spoofing, guest isolation, a per-server inbound firewall with rule sets
- **Server operations:** power actions, browser console (noVNC), reinstall with limits, root password, hostname, SSH keys, address changes, resource upgrades, moves between nodes, clusters and locations
- **Snapshots and backups:** snapshots, manual and scheduled backups with retention, restore
- **Usage:** live figures, usage history graphs, monthly traffic quotas and usage limits with suspend or stop
- **App Marketplace:** one-click OpenVPN, WireGuard, Docker, Portainer, Coolify, control panels (cPanel, Plesk, HestiaCP, CyberPanel, aaPanel), web stacks, WordPress, Windows apps — at order time or later, with actions, upgrades, logs and a recipe editor
- **Billing integration:** a versioned REST API with scoped tokens, idempotency and signed webhooks, plus WHMCS and WISECP modules
- **Admin panel:** reports with CSV export, audit log, email notifications, mandatory TOTP 2FA, branding, English and Turkish UI
- **Migration:** adopt existing Proxmox VMs without touching them

Planned: NAT VPS, LXC, rescue mode, throttling on traffic overrun, the Virtualizor importer, a reseller system and more billing modules (Blesta, HostBill, Paymenter, FOSSBilling).

See the [feature overview](docs/wiki/Features.md) for the details, [AGENTS.md](AGENTS.md) for the architecture and [the roadmap](AGENTS.md#10-roadmap), and [docs/adr](docs/adr) for the design decisions.

## Repositories

| Repository | Contents |
|---|---|
| [proxmotion](https://github.com/proxmotion-devs/proxmotion) | The panel, the API contract ([OpenAPI](docs/api/openapi.yaml)), deployment and documentation |
| [proxmotion-WHMCS](https://github.com/proxmotion-devs/proxmotion-WHMCS) | WHMCS 8–9 server module |
| [proxmotion-WISECP](https://github.com/proxmotion-devs/proxmotion-WISECP) | WISECP 5 server module |
| [proxmotion-recipes](https://github.com/proxmotion-devs/proxmotion-recipes) | App Marketplace recipes as `.proxapp` packages |

## Installation

- **Docker Compose** with the images published to GHCR: [docs/guides/docker-install.md](docs/guides/docker-install.md) ([ADR 0054](docs/adr/0054-production-docker-images.md))
- **Native installer** for Debian 12/13 and Ubuntu 22.04/24.04: [docs/guides/native-install.md](docs/guides/native-install.md) ([ADR 0055](docs/adr/0055-native-installer.md))

Both run the same commands, which you can also run by hand inside `panel/` once the code, `.env` and the dependencies are in place:

```bash
php artisan proxmotion:install   # checks requirements, migrates, creates the first administrator
php artisan proxmotion:upgrade   # after updating the code: migrations in maintenance mode, worker restarts
```

See [ADR 0052](docs/adr/0052-install-and-upgrade-commands.md) for what each step does. After signing in, *Admin → Help* shows the setup order. The panel's name, colour, logo and support contacts are set under *Panel settings → Branding* ([ADR 0053](docs/adr/0053-branding.md)).

OS templates: [docs/guides/os-templates.md](docs/guides/os-templates.md), from an ISO by hand: [iso-templates.md](docs/guides/iso-templates.md), [windows-templates.md](docs/guides/windows-templates.md), [freebsd-templates.md](docs/guides/freebsd-templates.md).

## Tech stack

Laravel 13 · PHP 8.3+ · Vue 3 + Inertia.js 3 + TypeScript · Tailwind CSS 4 · Vite+ · MariaDB · Redis · Reverb

## Development

Requirements: PHP 8.3+, Composer 2, Node.js 22+, MariaDB/MySQL (or SQLite for quick local work), Redis. On Windows, [Laravel Herd](https://herd.laravel.com/windows) is the easiest way to get PHP and Composer. PHP must be on `PATH` for the frontend commands too.

```bash
cd panel
composer setup
php artisan db:seed
composer dev
```

The seeder creates a local administrator, `admin@example.com`, with the password `password`. Never run it in production. Panel accounts are staff only; customers live in your billing system.

Administrators must set up two-factor authentication (TOTP) before they can open the admin panel. For local development only, you can turn this off with `PROXMOTION_REQUIRE_ADMIN_2FA=false` in `.env`.

Run all checks (the same ones CI runs) with `composer ci:check` inside `panel/`, and `shellcheck` on shell scripts. Tests never touch a real Proxmox; the optional integration suite against a test Proxmox is described in [docs/guides/real-proxmox-verification.md](docs/guides/real-proxmox-verification.md).

Or with Docker:

```bash
docker compose -f deploy/docker/compose.yml up -d
```

## Contributing

Contributions are welcome — please read [CONTRIBUTING.md](CONTRIBUTING.md) and our [Code of Conduct](CODE_OF_CONDUCT.md). To report a security issue, follow [SECURITY.md](SECURITY.md); do not open a public issue.

## License

Proxmotion is licensed under the [GNU Affero General Public License v3.0](LICENSE). If you run a modified version as a network service, you must make your modified source code available to its users.

Proxmox® is a registered trademark of Proxmox Server Solutions GmbH. This project is not affiliated with or endorsed by Proxmox Server Solutions GmbH, WHMCS, WISECP, Virtualizor or VirtFusion.
