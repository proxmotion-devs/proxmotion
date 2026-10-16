# Console proxy

Relays the browser console (noVNC) WebSocket to Proxmox VE, so the API token never reaches the browser. The panel makes every access decision; this service only redeems single-use tickets and relays bytes. See [ADR 0002](../../docs/adr/0002-console-websocket-proxy.md).

## Configuration

| Variable | Default | Description |
|---|---|---|
| `PANEL_URL` | — (required) | Base URL the proxy uses to reach the panel, e.g. `http://nginx` |
| `CONSOLE_PROXY_SECRET` | — (required) | Same value as `CONSOLE_PROXY_SECRET` in the panel's `.env` |
| `HOST` | `0.0.0.0` | Listen address |
| `PORT` | `8090` | Listen port |

The web server must forward `/console-ws` with WebSocket upgrade headers to this service (see `deploy/docker/nginx.conf`). `GET /healthz` answers `ok`.

## Development

```bash
npm install
npm test
PANEL_URL=http://localhost:8000 CONSOLE_PROXY_SECRET=… npm start
```

Without Docker, set `CONSOLE_WEBSOCKET_URL=ws://localhost:8090/console-ws` in the panel's `.env` so browsers connect to the proxy directly.
