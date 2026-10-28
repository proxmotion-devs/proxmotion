# 36. Live server statistics over WebSockets

- **Status:** Accepted
- **Date:** 2026-10-21

## Context

The server page shows a server's configured resources (vCPUs, memory, disk, port speed) but nothing about what it is doing. Administrators asked for live figures — CPU, memory, network and disk throughput, uptime — as cards at the top of the page, updated without reloading.

`AGENTS.md` names Laravel Reverb for realtime, but it was never installed: pages poll with `usePoll` (3 s while a task runs) and the app log viewer polls a JSON route every 2 s. The compose file already starts a `reverb` service that could not run.

Proxmox never pushes statistics. Whatever carries them to the browser, the panel has to ask Proxmox. `GET /nodes/{node}/qemu/{vmid}/status/current` answers for one VM with `cpu` (share of all its vCPUs, 0–1), `cpus`, `mem`, `maxmem`, the cumulative counters `netin`, `netout`, `diskread`, `diskwrite` (bytes since the VM process started) and `uptime`; `GET /cluster/resources?type=vm` has the same fields for every guest of a cluster in one request, and `GET .../rrddata?timeframe=hour` gives averages per minute (rates in bytes per second) for the last hour.

## Decision

### 1. Reverb for the page, the panel polls Proxmox

- **Laravel Reverb** is installed and becomes the panel's WebSocket server (`BROADCAST_CONNECTION=reverb`); the browser connects with `laravel-echo` and `pusher-js` (both MIT). Nothing from Proxmox reaches the browser: payloads carry only figures.
- A scheduled command, `servers:stats` (every 10 seconds, without overlapping), asks Proxmox **only for servers someone is watching**, one `status/current` request per server however many administrators watch it, and broadcasts each server's figures right away (`ShouldBroadcastNow`, no queue) as `stats.updated` on the private channel `admin.servers.{uuid}`.
- `/cluster/resources` would answer a whole cluster in one request, but its figures come from Proxmox's status daemon, which refreshes them about every 10 seconds: sampled every 10 seconds, two rounds can see the same counters (a rate of 0, then double). `status/current` is read when asked (believed, unverified), and the number of watched servers is bounded by the open admin pages.
- It does nothing when the broadcast connection is `log` or `null` (local development without Reverb), so no request reaches Proxmox for a socket nobody can open.
- A cluster that cannot be reached (or refuses the token) is skipped for that round, and a server Proxmox refuses is skipped alone; the cards then show their figures as old.

### 2. Who is watching

- The page calls `GET /admin/servers/{server}/stats` when it opens and every 30 seconds while it stays open. Each call marks the server as watched for 75 seconds (`LiveStats`, in the cache under one key guarded by a lock). A closed tab stops calling, so its server drops out of the next rounds within 75 seconds.
- Reverb's own connection events are not used for this: they fire inside the Reverb process, and a page that lost its socket would keep its server watched.

### 3. Rates and restarts

- Rates (network in and out, disk read and write, in bytes per second) are the difference of two samples of the counters divided by the seconds between them. The previous sample of each server is kept in the cache for 5 minutes.
- The counters start from zero when the VM starts again; a counter lower than before, a changed status or no previous sample gives no rate (`null`) for that round, never a negative one.
- CPU is shown as a percentage of the server's vCPUs (`cpu × 100`). Memory is what Proxmox reports as used (without a balloon driver, the memory the VM process holds on the host, not the guest's own view).
- Disk *usage* is not shown: Proxmox reports `disk: 0` for QEMU guests, and the guest agent's `get-fsinfo` would be a call into the guest every round. Traffic against the package's quota waits for traffic accounting (Phase 6).

### 4. Channel and route

- The broadcasting auth route runs behind the admin middleware (`web`, `auth`, `verified`, `2fa`); the channel callback allows every administrator (there are no roles, ADR 0007) and binds the server by its UUID. Billing systems have no channel. The page has no CSRF meta tag, so it authorizes channels itself with the `XSRF-TOKEN` cookie read at that moment. Reverb refuses client events (`accept_client_events_from: none`).
- `GET /admin/servers/{server}/stats` (throttled) answers the latest sample: the one a round of `servers:stats` stored when it is younger than 15 seconds, otherwise one fresh `status/current` request. With `history=1` it also answers the last hour from `rrddata`, used once when the cards appear so the sparklines are not empty.

### 5. Without a socket

When Echo is not configured (no `VITE_REVERB_APP_KEY`) or its connection is not `connected`, the cards ask the same route every 10 seconds instead of every 30, and say they are polling. So the cards work without Reverb, only less efficiently: one Proxmox request per open page every 10 seconds.

### 6. Deployment

- Docker Compose runs `php artisan reverb:start`; nginx forwards `/app` (WebSocket upgrade) to it. The native installer (Phase 8) needs a systemd unit and the same nginx location.
- `.env.example` gets the `REVERB_*` and `VITE_REVERB_*` settings. Without them the panel falls back to polling (§5).
- The channels are registered on the broadcaster when the panel boots, so `BROADCAST_CONNECTION=reverb` without `REVERB_APP_ID`, `REVERB_APP_KEY` or `REVERB_APP_SECRET` made every request and artisan command fail — the queue worker and scheduler too. `ReverbFallback` switches to the `log` broadcaster at boot instead; `servers:stats` names the missing settings on every run and in the log once an hour. Live figures are a convenience; provisioning must not stop for them.

### 7. Disk space (2026-10-27)

The disk I/O card shows rates only; how full the disk is cannot come from Proxmox, which reports `disk: 0` for QEMU VMs. The server page also shows a *Disk space* card under the four others, filled from the guest agent's `get-fsinfo` (`GuestAgentResource::fileSystems()`, `DiskSpace`):

- Only for a VM whose live status is `running`, and only in the answer to the page's own request (`GET /admin/servers/{server}/stats`, every 10 or 30 seconds), never in the samples `servers:stats` pushes: the figure changes slowly.
- The request never waits for the agent, which can take seconds to fail when it hangs (a VM paused in Proxmox still reports `running`). It answers with the last known figures (none before the first: the card then says it is asking, and the page asks again after 2 seconds, at most 5 times in a row, instead of waiting up to 30 seconds for its next request; an answer without figures keeps the disk space it has) and, when they are older than 60 seconds, asks the agent with `defer()` once the response has been sent; one refresh per server at a time (a cache lock), however many pages are open.
- An agent that did not answer is asked again after 15 seconds instead, since it is usually still starting after a boot. Figures older than 5 minutes are no longer shown.
- The sizes are the agent's `used-bytes` and `total-bytes` (like `df`: the blocks reserved for root left out). Entries without sizes, read-only images and optical media (`squashfs`, `iso9660`, `udf`, `CDFS`) and Windows volumes without a drive letter are skipped; a device mounted several times (bind mounts, btrfs subvolumes) is listed once by its shortest mount point. The root file system (`/`, `C:\`) comes first, then the others by mount point, at most 10.
- Admin panel only: the billing API and modules do not get it.

## Consequences

- Administrators see a server's load live; one request per watched server every 10 seconds serves every open page.
- Laravel Reverb 1.x requires `guzzlehttp/psr7` 2.x, so the panel moved from Guzzle 8 back to Guzzle 7 (Laravel 13 accepts both).
- The panel has one more long-running process (Reverb) and a WebSocket location in nginx; Reverb can later carry task progress and app logs, which still poll.
- The scheduler runs a command every 10 seconds; it returns at once when nobody is watching.
- Not verified on a real Proxmox or a running Reverb yet: the fields and their units in `status/current` and `rrddata` on PVE 8.x and 9.x with a pool-limited token, whether `status/current` is read live (and its `cpu` right on the first request a Proxmox worker answers), the counters after a reboot from inside the guest (QEMU keeps running), Reverb behind nginx with `wss://`, and the broadcasting auth request with the session cookie, CSRF and 2FA. For §7: `agent/get-fsinfo` with a pool-limited token (`VM.GuestAgent.Audit` on 9.x, `VM.Monitor` on 8.x), its `used-bytes` / `total-bytes` on Linux and Windows guests (older agents report none), how long a request to an agent that does not answer takes.
