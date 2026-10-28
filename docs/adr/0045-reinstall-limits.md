# 45. Reinstall limits

## Context

Customers reinstall their servers from the billing system's client area (`POST /servers/{id}/reinstall`). A reinstall clones the OS template again, which costs I/O on the node and, with full clones, minutes of disk writes. Providers want to limit how often customers can do this, per plan (package), with exceptions for single servers.

Administrators also reinstall servers from the admin panel, often on a customer's behalf (a support ticket), or to recover a broken server.

## Decision

1. **A package may limit reinstalls:** `packages.reinstall_limit` (how many, `0` = none) within `packages.reinstall_period_days` (a rolling window of 1–365 days). An empty limit means unlimited, the default, so nothing changes for existing packages.
2. **The limit is not copied to servers.** Unlike resources, which change the VM and are copied at creation, the limit is a panel-only rule: a server follows its package's current values, so editing a package changes the limit of all its servers at once, and a package change (upgrade) brings the new package's limit.
3. **A server may override it:** `servers.reinstall_limit_custom` with `servers.reinstall_limit` / `servers.reinstall_period_days` (an empty limit = unlimited). A server without a package has no limit unless it gets its own.
4. **Only reinstalls through the billing API count and are limited.** Administrators' reinstalls are never refused and do not use up the customer's reinstalls. Counted are reinstall tasks started with an API token of the server's billing system in the last `period` days that did not fail (a failed reinstall is the provider's problem, retried by an administrator).
5. **Reset:** an administrator may reset the count of a server (`servers.reinstalls_reset_at`): only reinstalls after it count. Changing the limit and resetting are audited (`server.reinstall_limit_changed`, `server.reinstalls_reset`).
6. **Over the limit** the API answers `409` with `error: reinstall_limit_reached` and `available_at` (when the oldest counted reinstall leaves the window; `null` for a limit of `0`). The check runs in `ServerReinstaller::request()` before anything changes, so the request has no effect.
7. **The API says where a server stands:** `GET /servers/{id}` has `reinstalls` (`limit`, `period_days`, `used`, `remaining`, `available_at`; `limit` null when unlimited); only on the single server, since counting is a query per server. `GET /packages` has `reinstall_limit` (`count`, `period_days`) or null. Additive, so v1 stays compatible.
8. **Billing modules:** the WHMCS client area shows how many reinstalls are left and, when none are, when the next one is possible instead of the form. WISECP shows the panel's message when its reinstall tool is refused.

## Consequences

- One query per check (`ReinstallLimits`), on the indexed tasks of one server.
- A rolling window needs no calendar or reset job and is the same for every customer; a calendar month (like traffic periods, ADR 0039) was not chosen because it lets a customer use two months' reinstalls back to back.
- Two reinstall requests at the same moment cannot both pass: the second finds the first's task running (`TaskRunner`).
- Reinstalls that are part of an app trial reset (ADR 0018 §7) run on test servers, which never have a billing system, so they are never limited.
- Not verified in a real WHMCS or WISECP.
