# 7. Billing systems only: no client panel

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

Up to Phase 3 Proxmotion had three faces: an admin panel, a client panel where customers logged in with their own panel account, and a billing API. Customers were `users` rows with the `client` role; servers belonged to them (`servers.user_id`), and they saved SSH keys in their settings.

Hosting providers already give their customers a client area: WHMCS, WISECP and similar billing systems. A second customer login next to it means two accounts per customer, two places to reset passwords and two attack surfaces facing the internet. The maintainers decided that Proxmotion works **only through billing systems**, via the API and a module per billing system.

## Decision

1. **There is no client panel and no customer account.** Panel accounts are staff (administrators) only; the `role` column, the `client` role and the `role` middleware are removed. Every panel user is an administrator and must confirm TOTP 2FA.
2. **Servers belong to the billing system, not to a panel user.** `servers.user_id` is removed. A server carries the billing system's ids: `external_id` (service id, unique) and `external_client_id` (customer id, optional, for searching and grouping). The panel stores no customer names or e-mail addresses.
3. **Customer actions move to the billing module.** Power, reinstall, root password reset, rDNS and app installs become API v1 endpoints (Phase 4), shown by the module in the billing system's client area. Tenant isolation is enforced by the billing system (a customer only sees their own services) plus scoped API tokens on the panel side.
4. **SSH keys are not stored as saved keys.** The `ssh_keys` table is removed. Public keys are passed with each create or reinstall (API or admin form) and stored on the server only as the keys installed by cloud-init.
5. **The browser console stays, without a login.** For customers, the API (Phase 4) issues a short-lived, signed, single-use console link for one server; the module opens it. The link opens only the console page of that server — the panel's single customer-facing page. Everything else in ADR 0002 (tickets, proxy, heartbeat, one session per server) is unchanged. Admins keep opening the console from the admin panel.
6. **Billing modules:** WHMCS first, then WISECP, both in Phase 4. Blesta, HostBill, Paymenter and FOSSBilling follow later.
7. The root password flag `root_password_unrevealed` stays: in Phase 4 it means "the billing system has not fetched the current password yet", so the API hands each new password out once. Admins can always look at it.

## Consequences

- The migration removing customers deletes `client` users and their saved SSH keys. There are no production installs yet, so no data needs to be carried over.
- SSO from the billing system (`POST /users/{id}/sso`) is dropped from API v1; the console link replaces the only part that needed a browser session.
- Everything a customer can do must exist in API v1 and in each billing module. Features without an API endpoint are admin-only.
- Admins create servers in the panel with optional billing ids; adopted servers get them the same way.
