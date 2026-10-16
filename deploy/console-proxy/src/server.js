import { createPanelClient } from './panel-client.js';
import { createConsoleProxy } from './proxy.js';

const panelUrl = process.env.PANEL_URL;
const secret = process.env.CONSOLE_PROXY_SECRET;
const host = process.env.HOST ?? '0.0.0.0';
const port = Number(process.env.PORT ?? 8090);

if (!panelUrl || !secret) {
    console.error('PANEL_URL and CONSOLE_PROXY_SECRET must be set.');
    process.exit(1);
}

const proxy = createConsoleProxy({
    panel: createPanelClient({ baseUrl: panelUrl, secret }),
});

proxy.server.listen(port, host, () => {
    console.info(`console proxy listening on ${host}:${port}`);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, async () => {
        await proxy.close();
        process.exit(0);
    });
}
