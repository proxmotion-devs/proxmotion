# 2. Console access through a separate WebSocket proxy

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

Admins and customers need a browser console (noVNC) for their KVM servers. Proxmox serves the VNC stream on `GET /nodes/{node}/qemu/{vmid}/vncwebsocket`, authenticated with the API token header. Browsers cannot set an `Authorization` header on a WebSocket, and the token must never reach the browser (AGENTS.md 7.2, 7.3). So something on the panel side must hold the token and relay the WebSocket.

PHP-FPM is a poor fit for long-lived WebSockets. Laravel Reverb speaks the Pusher protocol and cannot relay a raw binary stream.

## Decision

A small, stateless **Node.js service** (`deploy/console-proxy/`, `ws` library) relays the console. All decisions stay in Laravel:

1. The user opens the console page (`/admin/servers/{uuid}/console`). A Policy checks that the server is active. (Since ADR 0007 there is no client panel; customers will reach the console page through a signed single-use link from the billing API.)
2. Laravel calls `vncproxy` (`websocket=1`, `generate-password=1`) and stores the result under a random **single-use ticket** in the cache for 15 seconds (Proxmox only waits about 10 seconds for the connection). The cached payload, which includes the token header, is encrypted with the app key. The page receives the WebSocket URL with the ticket and the one-time VNC password. It never receives the vncticket or the token.
3. The browser connects to `wss://<panel>/console-ws?ticket=…`. The proxy redeems the ticket at `POST /internal/console/redeem` (shared secret header `X-Console-Proxy-Secret`). Laravel deletes the ticket while it holds a lock, so a ticket works only once.
4. The proxy opens `wss://<host>:<port>/api2/json/nodes/{node}/qemu/{vmid}/vncwebsocket` with the token header, trying each cluster host in turn, and relays bytes in both directions.
5. Every 15 seconds the proxy calls `POST /internal/console/heartbeat`. Laravel answers `active: false` once the server is no longer active (suspended, terminated, …) or a newer console session was opened for the same server (**one session per server**). The proxy then closes the connection.
6. The proxy enforces the maximum session length (`expires_at`, default 60 minutes). The browser closes the session after 15 minutes without keyboard or mouse input, because noVNC keeps sending framebuffer requests and the proxy cannot see "idle" in the stream.
7. Every console opening is written to the `audit_logs` table (`console.opened`).

The token needs `VM.Console` on the server pool; the permission check lists it.

## Consequences

- One more service to run (a Docker Compose service now; a systemd unit in the native installer in Phase 8). It needs only `PANEL_URL` and `CONSOLE_PROXY_SECRET`.
- The proxy holds no state and makes no access decisions, so it can be restarted at any time. Open consoles drop and users reconnect.
- The proxy honours each cluster's "verify TLS" setting. That is the same trust as the PHP client.
- LXC (`termproxy` + xterm.js) can reuse the same flow later.
- Not yet verified against a real Proxmox server: API-token authentication on `vncwebsocket` and the `generate-password` option (Proxmox VE 8+).
