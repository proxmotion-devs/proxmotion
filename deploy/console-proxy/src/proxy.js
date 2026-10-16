import http from 'node:http';
import { WebSocket, WebSocketServer } from 'ws';

/**
 * The console proxy (docs/adr/0002-console-websocket-proxy.md).
 *
 * The browser connects to `<path>?ticket=…`. The ticket is redeemed at the
 * panel for the Proxmox vncwebsocket URLs and the API token header, then bytes
 * are relayed both ways. The proxy makes no access decisions itself: the panel
 * decides at redeem time and at every heartbeat.
 *
 * Never log tickets, VNC tickets or the authorization header.
 *
 * @param {{
 *   panel: { redeem(ticket: string): Promise<object|null>, heartbeat(session: string): Promise<boolean> },
 *   path?: string,
 *   heartbeatMs?: number,
 *   maxHeartbeatFailures?: number,
 *   upstreamTimeoutMs?: number,
 *   logger?: Pick<Console, 'info'|'warn'|'error'>,
 * }} options
 */
export function createConsoleProxy({
    panel,
    path = '/console-ws',
    heartbeatMs = 15_000,
    maxHeartbeatFailures = 3,
    upstreamTimeoutMs = 8_000,
    logger = console,
}) {
    const wss = new WebSocketServer({
        noServer: true,
        // VNC client messages are tiny; the clipboard is the largest.
        maxPayload: 256 * 1024,
        handleProtocols: (protocols) => (protocols.has('binary') ? 'binary' : false),
    });

    const server = http.createServer((request, response) => {
        if (request.url === '/healthz') {
            response.writeHead(200, { 'Content-Type': 'text/plain' }).end('ok');

            return;
        }

        response.writeHead(404).end();
    });

    server.on('upgrade', (request, socket, head) => {
        handleUpgrade(request, socket, head).catch((error) => {
            logger.error(`console: upgrade failed: ${error.message}`);
            reject(socket, 500, 'Internal Server Error');
        });
    });

    async function handleUpgrade(request, socket, head) {
        const url = new URL(request.url ?? '/', 'http://localhost');
        const ticket = url.searchParams.get('ticket');

        if (url.pathname !== path || !ticket) {
            reject(socket, 404, 'Not Found');

            return;
        }

        const connection = await panel.redeem(ticket);
        const expiresAt = connection ? Date.parse(connection.expires_at) : NaN;

        if (!connection || !(expiresAt > Date.now())) {
            reject(socket, 401, 'Unauthorized');

            return;
        }

        let upstream;

        try {
            upstream = await connectUpstream(connection.upstream, upstreamTimeoutMs);
        } catch (error) {
            logger.warn(`console: server ${connection.server}: Proxmox unreachable: ${error.message}`);
            reject(socket, 502, 'Bad Gateway');

            return;
        }

        // Don't leave the Proxmox connection open if the browser went away meanwhile.
        socket.once('close', () => closeQuietly(upstream, 1000, ''));

        wss.handleUpgrade(request, socket, head, (client) => {
            relay(client, upstream, connection, expiresAt);
        });
    }

    function relay(client, upstream, connection, expiresAt) {
        const { session, server: serverId } = connection;
        let closed = false;
        let heartbeatFailures = 0;

        logger.info(`console: server ${serverId}: session opened`);

        const close = (code, reason) => {
            if (closed) {
                return;
            }

            closed = true;
            clearTimeout(expiryTimer);
            clearInterval(heartbeatTimer);
            closeQuietly(client, code, reason);
            closeQuietly(upstream, 1000, '');
            logger.info(`console: server ${serverId}: session closed (${reason || code})`);
        };

        const expiryTimer = setTimeout(() => close(4000, 'Session expired'), Math.max(0, expiresAt - Date.now()));

        const heartbeatTimer = setInterval(async () => {
            try {
                const active = await panel.heartbeat(session);
                heartbeatFailures = 0;

                if (!active) {
                    close(4001, 'Session ended');
                }
            } catch (error) {
                heartbeatFailures++;
                logger.warn(`console: server ${serverId}: heartbeat failed: ${error.message}`);

                // Fail closed when the panel stays unreachable.
                if (heartbeatFailures >= maxHeartbeatFailures) {
                    close(4002, 'Panel unreachable');
                }
            }
        }, heartbeatMs);

        client.on('message', (data, isBinary) => {
            if (upstream.readyState === WebSocket.OPEN) {
                upstream.send(data, { binary: isBinary });
            }
        });
        upstream.on('message', (data, isBinary) => {
            if (client.readyState === WebSocket.OPEN) {
                client.send(data, { binary: isBinary });
            }
        });

        client.on('close', () => close(1000, 'Client closed'));
        upstream.on('close', () => close(1000, 'Console closed'));
        client.on('error', () => close(1011, 'Client error'));
        upstream.on('error', () => close(1011, 'Console error'));
    }

    return {
        server,
        /** Close all sessions and stop listening. */
        async close() {
            for (const client of wss.clients) {
                closeQuietly(client, 1001, 'Proxy shutting down');
            }

            await new Promise((resolve) => server.close(() => resolve()));
            server.closeAllConnections?.();
        },
    };
}

/**
 * Connect to the first vncwebsocket URL that answers (cluster host failover).
 *
 * @param {{ urls: string[], authorization: string, verify_tls: boolean }} upstream
 */
export async function connectUpstream(upstream, timeoutMs) {
    const errors = [];

    for (const url of upstream.urls ?? []) {
        try {
            return await openSocket(url, upstream, timeoutMs);
        } catch (error) {
            errors.push(error.message);
        }
    }

    throw new Error(errors.length > 0 ? errors.join('; ') : 'no upstream URLs');
}

function openSocket(url, upstream, timeoutMs) {
    return new Promise((resolve, reject) => {
        const socket = new WebSocket(url, ['binary'], {
            headers: { Authorization: upstream.authorization },
            rejectUnauthorized: upstream.verify_tls !== false,
            handshakeTimeout: timeoutMs,
            perMessageDeflate: false,
        });

        const fail = (error) => {
            socket.removeAllListeners();
            socket.on('error', () => {});
            socket.terminate();
            // Error messages contain the host, never the query string or headers.
            reject(new Error(`${new URL(url).host}: ${error.message}`));
        };

        socket.once('open', () => {
            socket.removeListener('error', fail);
            socket.removeListener('unexpected-response', unexpected);
            // relay() adds the real handler; until then an error must not crash the process.
            socket.on('error', () => {});
            resolve(socket);
        });
        socket.once('error', fail);

        const unexpected = (_request, response) => fail(new Error(`HTTP ${response.statusCode}`));
        socket.once('unexpected-response', unexpected);
    });
}

function reject(socket, status, message) {
    if (socket.writable) {
        socket.write(`HTTP/1.1 ${status} ${message}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n`);
    }

    socket.destroy();
}

function closeQuietly(socket, code, reason) {
    try {
        if (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING) {
            socket.close(code, reason);
        }
    } catch {
        socket.terminate();
    }
}
