# 8. Billing API v1: tokens, scopes, idempotency and console links

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

With no client panel (ADR 0007), every customer action reaches the panel through the public API v1, called by a billing module (WHMCS first). The caller is a billing system, not a person: it holds one long-lived credential, retries requests when its own cron or a network hop fails, and opens the browser console on behalf of a customer who has no panel account. AGENTS.md 7 requires scoped, hashed, IP-restricted, rate-limited tokens, idempotent write endpoints and single-use console links of at most 60 seconds.

## Decision

0. **Billing systems.** Every token belongs to a **billing system** (`billing_systems`: a name such as "WHMCS"), and every server ordered through the API to the billing system of its token (`servers.billing_system_id`). A token only reaches servers and tasks of its own billing system; others answer exactly like a server that does not exist (`404`). `external_id` is unique per billing system, so two billing systems may both have a service 1001. Tokens can be replaced (revoke and create a new one) without servers changing hands. Admins may assign servers they create or adopt to a billing system; billing ids require one. Servers without a billing system are not reachable through the API.
1. **Own token model, no Sanctum.** Tokens belong to an integration, not to a panel user, so they live in their own `api_tokens` table: name, `token_hash` (SHA-256 of the secret, unique), the first characters for display, scopes, optional allowed IPs/CIDRs, optional expiry, `last_used_at`/`last_used_ip`, `revoked_at` and the administrator who created it. The secret has the form `pmx_<48 random characters>` and is shown once. A random 48-character secret has enough entropy that a fast hash is safe; no password hashing is needed. Sanctum would tie tokens to an authenticatable model and adds nothing we need.
2. **Scopes** (`ApiScope` enum), medium-grained:
   | Scope | Allows |
   |---|---|
   | `catalog:read` | packages, locations, OS templates |
   | `servers:read` | servers, their addresses and tasks |
   | `servers:write` | create, suspend, unsuspend, terminate, power, reinstall, password reset, rDNS |
   | `servers:secrets` | fetch a new root password (once), console links |
   `apps:read`, `apps:write` and `apps:secrets` follow in Phase 5. A billing module gets all scopes; a reporting integration only the read ones.
3. **Request pipeline** (`/api/v1`): `AuthenticateApiToken` (Bearer token → active, unexpired, allowed IP; else `401`/`403`), `RequireApiScope` per route (`403`), a rate limiter per token (`api`: 120 requests per minute), and for write routes `HandleIdempotency`.
4. **Idempotency.** A write request may send `Idempotency-Key` (1–255 visible ASCII characters). The key is stored per token with a hash of method, path and body. A replay with the same request returns the stored status and body (`Idempotent-Replayed: true`); the same key with another request is `422`; a key whose first request is still running is `409`. Server errors (`5xx`) are not stored, so the client can retry. Keys are pruned after 24 hours. Creating a server additionally requires a unique `external_id`, which guards against duplicates even without the header.
5. **Actor.** Tasks and audit log entries record either the administrator (`user_id`) or the API token (`api_token_id`). Every API write is audited with the token, the request IP and the server.
6. **Console links.** `POST /servers/{id}/console` returns a temporary signed URL (60 seconds) carrying a random single-use nonce. Opening it consumes the nonce and stores a *console grant* for that one server in the browser session (valid for the console's maximum session length, `proxmotion.console.max_minutes`), then redirects to the console page. Reconnects on that page use the grant; nothing else in the panel accepts it. Console sessions opened through a grant end when the server is no longer active or the token is revoked. The rest of ADR 0002 is unchanged.
7. **Errors** are JSON: `{"message": …}` plus `errors` for validation (`422`). A request that conflicts with the server's state or a running task is `409`; Proxmox being unreachable is `503`. Operations that change Proxmox return `202` with the queued task; clients poll `GET /tasks/{id}`.
8. **Server resources** come from a package; the request may override CPU, memory, disk, address counts, port speed and traffic (WHMCS configurable options) within the limits of the admin form.

## Consequences

- More tables to maintain (`billing_systems`, `api_tokens`, `idempotency_keys`) and an admin page for billing systems and their tokens.
- Moving a server to another billing system (e.g. when switching from WHMCS to WISECP) is an admin action, not an API one; it is not built yet.
- Domain services accept a `User|ApiToken` actor instead of only a `User`.
- The console page is reachable without a panel login, but only through a signed link that the billing system requests with `servers:secrets`, and only for one server.
