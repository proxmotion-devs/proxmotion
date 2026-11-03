import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import type { ReverbClient } from '@/types/ui';

const { page, options } = vi.hoisted(() => ({
    page: { reverb: null as ReverbClient | null },
    options: [] as Record<string, unknown>[],
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: page }),
}));

vi.mock('laravel-echo', () => ({
    default: class {
        constructor(settings: Record<string, unknown>) {
            options.push(settings);
        }
    },
}));

vi.mock('pusher-js', () => ({ default: class {} }));

// echo() keeps its connection, so every test loads the module afresh.
const load = async () => (await import('@/lib/echo')).echo();

describe('echo', () => {
    beforeEach(() => {
        vi.resetModules();
        options.length = 0;
    });

    it('is off without Reverb settings from the page', async () => {
        page.reverb = null;

        expect(await load()).toBeNull();
        expect(options).toHaveLength(0);
    });

    it("uses the panel's own host and scheme when the settings leave them out", async () => {
        page.reverb = { key: 'app-key', host: null, port: null, scheme: null };

        await load();

        expect(options[0]).toMatchObject({
            key: 'app-key',
            wsHost: window.location.hostname,
            forceTLS: window.location.protocol !== 'http:',
        });
    });

    it("takes the page's port only with the page's scheme", async () => {
        // happy-dom's page is http://localhost:3000.
        page.reverb = { key: 'app-key', host: null, port: null, scheme: null };
        await load();
        expect(options[0]).toMatchObject({ wsPort: 3000 });

        vi.resetModules();
        page.reverb = {
            key: 'app-key',
            host: null,
            port: null,
            scheme: 'https',
        };
        await load();
        expect(options[1]).toMatchObject({ wsPort: 443, forceTLS: true });
    });

    it('uses the host, port and scheme it is given', async () => {
        page.reverb = {
            key: 'app-key',
            host: 'ws.example.com',
            port: 8080,
            scheme: 'http',
        };

        await load();

        expect(options[0]).toMatchObject({
            wsHost: 'ws.example.com',
            wsPort: 8080,
            wssPort: 8080,
            forceTLS: false,
        });
    });
});
