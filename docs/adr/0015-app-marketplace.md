# 15. App Marketplace: recipes and installs through the guest agent

- **Status:** Accepted
- **Date:** 2026-10-17

## Context

Phase 5 lets customers install applications (OpenVPN, WireGuard, Docker, later control panels and web stacks) on their server from the billing system's client area (AGENTS.md 3.5). ADR 0004 decided that everything inside a VPS goes through the QEMU guest agent, without cloud-init snippets, phone-home requests or SSH. ADR 0010 already runs short guest scripts that way, but an app install can take fifteen minutes, longer than a queue job should wait and longer than a server should be locked against other operations.

Phase 5a covers the recipe format, the bundled recipes OpenVPN, WireGuard and Docker, and installing an app on a running server through the API. Installing at order time and at reinstall (5b), paid apps (5b), app actions such as adding a VPN client and custom recipes (5c) come later.

## Decision

1. **Recipes** live in `panel/recipes/<slug>/` (`recipe.yaml`, `install.sh`, `README.md`) and are validated against `recipes/_schema/recipe.schema.json` (JSON Schema 2020-12, checked with `opis/json-schema`; YAML read with `symfony/yaml`). `php artisan apps:validate` validates them (CI); `php artisan apps:sync` copies them with their scripts into `app_recipes` — installs use the stored copy, so a custom recipe (5c) works the same way. A recipe removed from the repository is deactivated, never deleted (installations keep their recipe). New recipes are active; administrators can deactivate one.
2. **Installations** (`app_installations`) belong to a server and a recipe and record the recipe version, the validated inputs (encrypted, they may hold passwords), status, progress, the current step, the error and the outputs (encrypted). Statuses: `queued → installing → installed`, `queued|installing → failed` (retryable), `installed|failed → removed` when the server is reinstalled or terminated.
3. **Starting** is a short `install_app` server task: the server must be active and its agent must answer. The task writes to `/var/lib/proxmotion/apps/<installation id>/` the recipe's `install.sh`, the shared helpers (`recipes/_lib/proxmotion.sh`), the runner (`recipes/_lib/run.sh`), `inputs.json` and `server.json` (hostname and primary addresses), then starts the runner detached with `systemd-run` and finishes. The server is not locked while the app installs; one app installs at a time per server.
4. **Progress** is read, not reported: the runner and the script write `status.json` (`installing`, `installed` or `failed`, progress, step, message); `php artisan apps:poll` (every minute) queues a `PollAppInstallation` job per installing app, which reads it with `file-read`. On `installed` it reads `outputs.json` and the declared files from `outputs/` and stores them encrypted. An install past the recipe's `timeout_minutes` fails, also when the agent stopped answering. No token is placed on the VPS and the VPS needs no route to the panel.
5. **Inputs are never interpolated into scripts.** They are validated against the declared types (`string`, `password`, `integer`, `port`, `boolean`, `enum`, `slug`, `email`, `domain`) and written as `inputs.json`; scripts read them with `jq` (the runner installs it when missing). Generated values (passwords, keys) are made inside the VPS by the script.
6. **Eligibility** is checked by the panel: active recipe, Linux template whose family and version the recipe lists, the server's (or package's) CPU, memory and disk at least the recipe's requirements, a network model the recipe lists, `post` among its install modes, and `billing: free` until entitlements exist (5b; *since 2026-10-18* paid apps need an entitlement, ADR 0019). Ineligible apps are not listed; requests for them answer `422`.
7. **API:** `GET /apps` (catalog, optionally filtered by OS template, package or server), `POST /servers/{id}/apps` (`servers:write`, idempotent, `202`), `GET /servers/{id}/apps` and `GET /servers/{id}/apps/{installation}` (`servers:read`). Outputs are only included for tokens with the new `apps:secrets` scope; installing and reading use the existing scopes, so existing tokens keep working. Webhooks `app.installed` and `app.failed` carry the server and the installation without outputs.
8. **Firewall:** the VM firewall's input policy is `ACCEPT` (only anti-spoofing filters), so a recipe's `firewall` ports are recorded but not applied; NAT port forwards (Phase 9) and customer firewall rules will use them.

## Consequences

- One install path, also for the order-time and reinstall installs of 5b, which only start the same flow after the post-boot wait.
- Apps need a Linux template with systemd, a working guest agent and outbound internet access for package downloads.
- The panel learns about progress with up to a minute of delay; billing modules poll the installation or wait for the webhook.
- Secret outputs (VPN client files) are stored encrypted in the panel and fetched by the billing module on demand; they are never written to the billing system's database or to logs.
- The recipes' scripts have only been checked with shellcheck and the faked agent; real installs belong to the `recipes` integration group.
