# 13. Webhooks to billing systems

- **Status:** Accepted
- **Date:** 2026-10-17

## Context

Billing modules learn the outcome of an operation by polling `GET /tasks/{id}` (ADR 0008). That works for an operation the module itself started, while the client or the admin is looking at it, but not for:

- the end of a long first provisioning after WHMCS's `CreateAccount` call has returned,
- operations an administrator starts in the panel (suspend, reinstall, terminate), which the billing system never hears about,
- failures that need the billing system's attention (a server stuck in `error`).

AGENTS.md 4.1 plans optional HMAC-SHA256 signed webhooks.

## Decision

1. **Endpoints belong to a billing system.** `webhook_endpoints` stores the URL, a signing secret (`whsec_` + 40 random characters, `encrypted` cast, shown once after creation or replacement), the subscribed events, an `active` flag and a counter of deliveries that failed in a row. A billing system may have several endpoints. Only administrators manage them (*Admin → Billing systems*), every change is audited. The URL may be `http` or `https` and may point to a private address, because the billing system is often on the provider's own network; only administrators enter it.
2. **Events come from finished tasks.** `RunServerTask` calls `Webhooks::taskFinished()` after a task succeeded or failed; this covers every pipeline at one place, and operations started by administrators as well as by the API. A webhook problem is reported but never changes the task's outcome. Servers without a billing system send nothing; a billing system only ever receives events of its own servers.
   | Event | When |
   |---|---|
   | `server.provisioned`, `server.suspended`, `server.unsuspended`, `server.reinstalled`, `server.terminated` | The task of that type succeeded |
   | `server.failed` | A create, reinstall or terminate task failed (the server is in `error`) |
   | `task.succeeded`, `task.failed` | Any task |
   | `ping` | An administrator sent a test (cannot be subscribed to) |
   A server event and the task event of the same task are separate deliveries with their own event ids, so an endpoint can subscribe to either or both.
3. **Payload** `{id, event, created_at, data: {server, task}}`: `server` has the public id, `external_id`, `external_client_id`, hostname and status; `task` is the API's task resource. Nothing secret (root passwords, console links, the task's secret input) is ever included. The body is stored as sent (`webhook_deliveries.payload`), so a delivery sent again is byte-for-byte the same and keeps its event id.
4. **Signature** in `Proxmotion-Signature: t=<unix timestamp>,v1=<hex HMAC-SHA256 of "<t>.<body>">`, like Stripe's scheme: the timestamp lets receivers refuse replays, the version prefix allows changing the algorithm later. `Proxmotion-Webhook-Id` and `Proxmotion-Webhook-Event` repeat the id and name.
5. **Delivery** (`DeliverWebhook` job, queued after commit): 10-second timeout, no redirects (a redirect could lead a request elsewhere), only `2xx` counts. A failed attempt is queued again after 1 min, 5 min, 30 min, 1 h, 2 h, 6 h and 12 h — 8 attempts over about 22 hours — then the delivery is marked failed and the endpoint's failure counter grows; a success resets it. Disabling an endpoint stops its pending retries. Test events and deliveries an administrator sends again are tried once. Deliveries are pruned after 30 days.
6. **At least once, no ordering.** A retry can arrive after a later event, and a timeout after the receiver already acted causes a repeat. Receivers drop repeats by event id and fetch the current server or task from the API when order matters.

## Consequences

- Billing modules can stop polling for operations they did not start and can react to failures; polling `GET /tasks/{id}` keeps working and remains the fallback.
- The WHMCS module receives them in `modules/servers/proxmotion/webhook.php` (signing secret in the WHMCS server's access hash; see the module's README) and still polls the tasks it started itself. A receiver for WISECP comes with that module.
- Endpoints are not disabled automatically after repeated failures; the failure counter is shown to administrators instead.
- Two new tables and a queue job; each finished task of a server with endpoints adds up to two rows per endpoint.
