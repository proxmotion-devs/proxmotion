# Proxmotion

**Open-source VPS management panel for Proxmox VE** — in the spirit of Virtualizor and VirtFusion.

> ⚠️ **Status: early development (Phase 0).** Not ready for production use.

Proxmotion lets hosting providers connect their Proxmox nodes and clusters, sell VPS through billing systems such as WHMCS, and give customers a self-service panel.

## Planned features

- **Mixed infrastructure:** standalone Proxmox nodes and clusters side by side
- **Automatic provisioning** from cloud-init templates, triggered by your billing system
- **Network models:** bridged public IPs, routed/failover IPs, NAT VPS, IPv6
- **Client panel:** power actions, browser console (noVNC), reinstall, rDNS, SSH keys, graphs
- **App Marketplace:** one-click OpenVPN, WireGuard, Docker, control panels and web stacks — at order time or later
- **Billing integration:** REST API + WHMCS module (Blesta, HostBill, Paymenter, FOSSBilling later)
- **Migration:** adopt existing Proxmox VMs, import from Virtualizor
- Snapshots, backups, traffic accounting and quotas, audit log, 2FA
- English and Turkish UI

See [AGENTS.md](AGENTS.md) for the full architecture and [the roadmap](AGENTS.md#10-roadmap).

## Tech stack

Laravel 13 · PHP 8.3 · Vue 3 + Inertia.js 3 + TypeScript · Tailwind CSS 4 · Vite+ · MariaDB · Redis · Horizon · Reverb

## Development

Requirements: PHP 8.3+, Composer 2, Node.js 22+, MariaDB/MySQL (or SQLite for quick local work), Redis. On Windows, [Laravel Herd](https://herd.laravel.com/windows) is the easiest way to get PHP and Composer.

```bash
cd panel
composer setup
php artisan db:seed
composer dev
```

The seeder creates two local accounts, `admin@example.com` and `client@example.com`, both with the password `password`. Never run it in production.

Run all checks (the same ones CI runs) with `composer ci:check`.

Or with Docker:

```bash
docker compose -f deploy/docker/compose.yml up -d
```

## Contributing

Contributions are welcome — please read [CONTRIBUTING.md](CONTRIBUTING.md) and our [Code of Conduct](CODE_OF_CONDUCT.md). To report a security issue, follow [SECURITY.md](SECURITY.md); do not open a public issue.

## License

Proxmotion is licensed under the [GNU Affero General Public License v3.0](LICENSE). If you run a modified version as a network service, you must make your modified source code available to its users.

Proxmox® is a registered trademark of Proxmox Server Solutions GmbH. This project is not affiliated with or endorsed by Proxmox Server Solutions GmbH, WHMCS, Virtualizor or VirtFusion.
