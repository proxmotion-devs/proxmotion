import assert from 'node:assert/strict';
import { once } from 'node:events';
import { afterEach, test } from 'node:test';
import { WebSocket, WebSocketServer } from 'ws';
import { createPanelClient } from '../src/panel-client.js';
import { createConsoleProxy } from '../src/proxy.js';

const cleanup = [];

afterEach(async () => {
    while (cleanup.length > 0) {
        await cleanup.pop()();
    }
});

const silent = { info() {}, warn() {}, error() {} };

/** A fake Proxmox vncwebsocket endpoint that echoes with a prefix. */
async function startUpstream() {
    const handshakes = [];
    const wss = new WebSocketServer({ port: 0, handleProtocols: () => 'binary' });
    wss.on('connection', (socket, request) => {
        handshakes.push({ url: request.url, headers: request.headers });
        socket.on('message', (data) => socket.send(Buffer.concat([Buffer.from('echo:'), data])));
    });
    await once(wss, 'listening');
    cleanup.push(() => new Promise((resolve) => wss.close(resolve)));

    return { wss, handshakes, url: `ws://127.0.0.1:${wss.address().port}/api2/json/nodes/pve1/qemu/100/vncwebsocket?port=5900&vncticket=secret` };
}

/** An in-memory panel: tickets map to connections, sessions to active flags. */
function fakePanel(tickets = {}) {
    const active = new Map();

    return {
        active,
        async redeem(ticket) {
            const connection = tickets[ticket] ?? null;
            delete tickets[ticket];

            return connection;
        },
        async heartbeat(session) {
            return active.get(session) ?? true;
        },
    };
}

function connection(urls, overrides = {}) {
    return {
        session: 'session-1',
        server: 'server-uuid',
        expires_at: new Date(Date.now() + 60_000).toISOString(),
        upstream: { urls, authorization: 'PVEAPIToken=proxmotion@pve!panel=token', verify_tls: true },
        ...overrides,
    };
}

async function startProxy(panel, options = {}) {
    const proxy = createConsoleProxy({ panel, logger: silent, heartbeatMs: 50, ...options });
    proxy.server.listen(0, '127.0.0.1');
    await once(proxy.server, 'listening');
    cleanup.push(() => proxy.close());

    return `ws://127.0.0.1:${proxy.server.address().port}/console-ws`;
}

async function connectClient(url) {
    const client = new WebSocket(url, ['binary']);
    const [event] = await Promise.race([
        once(client, 'open').then(() => ['open']),
        once(client, 'unexpected-response').then(([, response]) => [response.statusCode]),
    ]);
    cleanup.push(() => client.terminate());

    return { client, event };
}

test('relays bytes both ways and authenticates upstream with the token header', async () => {
    const upstream = await startUpstream();
    const panel = fakePanel({ good: connection([upstream.url]) });
    const proxyUrl = await startProxy(panel);

    const { client, event } = await connectClient(`${proxyUrl}?ticket=good`);
    assert.equal(event, 'open');
    assert.equal(client.protocol, 'binary');

    client.send(Buffer.from('hello'));
    const [reply] = await once(client, 'message');

    assert.equal(reply.toString(), 'echo:hello');
    assert.equal(upstream.handshakes[0].headers.authorization, 'PVEAPIToken=proxmotion@pve!panel=token');
    assert.equal(upstream.handshakes[0].headers['sec-websocket-protocol'], 'binary');
    assert.match(upstream.handshakes[0].url, /vncticket=secret/);
});

test('a ticket works only once', async () => {
    const upstream = await startUpstream();
    const proxyUrl = await startProxy(fakePanel({ good: connection([upstream.url]) }));

    assert.equal((await connectClient(`${proxyUrl}?ticket=good`)).event, 'open');
    assert.equal((await connectClient(`${proxyUrl}?ticket=good`)).event, 401);
});

test('rejects unknown tickets, missing tickets and other paths', async () => {
    const proxyUrl = await startProxy(fakePanel());

    assert.equal((await connectClient(`${proxyUrl}?ticket=nope`)).event, 401);
    assert.equal((await connectClient(proxyUrl)).event, 404);
    assert.equal((await connectClient(proxyUrl.replace('/console-ws', '/other?ticket=x'))).event, 404);
});

test('rejects expired sessions', async () => {
    const upstream = await startUpstream();
    const expired = connection([upstream.url], { expires_at: new Date(Date.now() - 1000).toISOString() });
    const proxyUrl = await startProxy(fakePanel({ old: expired }));

    assert.equal((await connectClient(`${proxyUrl}?ticket=old`)).event, 401);
    assert.equal(upstream.handshakes.length, 0);
});

test('fails over to the next cluster host', async () => {
    const upstream = await startUpstream();
    const proxyUrl = await startProxy(fakePanel({ good: connection(['ws://127.0.0.1:1/unreachable', upstream.url]) }));

    const { event } = await connectClient(`${proxyUrl}?ticket=good`);

    assert.equal(event, 'open');
    assert.equal(upstream.handshakes.length, 1);
});

test('answers 502 when no host is reachable', async () => {
    const proxyUrl = await startProxy(fakePanel({ good: connection(['ws://127.0.0.1:1/unreachable']) }));

    assert.equal((await connectClient(`${proxyUrl}?ticket=good`)).event, 502);
});

test('closes the session when the panel ends it', async () => {
    const upstream = await startUpstream();
    const panel = fakePanel({ good: connection([upstream.url]) });
    const proxyUrl = await startProxy(panel);

    const { client } = await connectClient(`${proxyUrl}?ticket=good`);
    panel.active.set('session-1', false);
    const [code] = await once(client, 'close');

    assert.equal(code, 4001);
});

test('closes the session when it expires', async () => {
    const upstream = await startUpstream();
    const shortLived = connection([upstream.url], { expires_at: new Date(Date.now() + 200).toISOString() });
    const proxyUrl = await startProxy(fakePanel({ good: shortLived }), { heartbeatMs: 10_000 });

    const { client } = await connectClient(`${proxyUrl}?ticket=good`);
    const [code] = await once(client, 'close');

    assert.equal(code, 4000);
});

test('closes the session when the panel stays unreachable', async () => {
    const upstream = await startUpstream();
    const panel = fakePanel({ good: connection([upstream.url]) });
    panel.heartbeat = async () => {
        throw new Error('down');
    };
    const proxyUrl = await startProxy(panel);

    const { client } = await connectClient(`${proxyUrl}?ticket=good`);
    const [code] = await once(client, 'close');

    assert.equal(code, 4002);
});

test('panel client sends the secret and maps 404 to null', async () => {
    const calls = [];
    const fetchImpl = async (url, init) => {
        calls.push({ url, init });

        return url.endsWith('/redeem')
            ? new Response('{}', { status: 404 })
            : new Response(JSON.stringify({ active: true }), { status: 200 });
    };
    const panel = createPanelClient({ baseUrl: 'http://panel.test/', secret: 's3cret', fetchImpl });

    assert.equal(await panel.redeem('t'), null);
    assert.equal(await panel.heartbeat('s'), true);
    assert.equal(calls[0].url, 'http://panel.test/internal/console/redeem');
    assert.equal(calls[0].init.headers['X-Console-Proxy-Secret'], 's3cret');
    assert.deepEqual(JSON.parse(calls[1].init.body), { session: 's' });
});
