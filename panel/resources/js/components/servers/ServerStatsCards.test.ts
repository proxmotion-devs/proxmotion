import { flushPromises, mount } from '@vue/test-utils';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import ServerStatsCards from '@/components/servers/ServerStatsCards.vue';
import { POLL_MS, WATCH_MS } from '@/composables/useServerStats';
import type { ServerStats } from '@/types/admin';

const socket = vi.hoisted(() => ({
    enabled: false,
    connected: false,
    pushed: null as ((sample: unknown) => void) | null,
    stateListener: null as ((connected: boolean) => void) | null,
    left: [] as string[],
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en' } }),
}));

vi.mock('laravel-vue-i18n', () => ({ trans: (key: string) => key }));

vi.mock('@/lib/echo', () => ({
    echo: () =>
        socket.enabled
            ? {
                  private: () => ({
                      listen: (_event: string, callback: () => void) => {
                          socket.pushed = callback;
                      },
                  }),
                  leave: (channel: string) => socket.left.push(channel),
              }
            : null,
    onConnection: (_echo: unknown, listener: (connected: boolean) => void) => {
        socket.stateListener = listener;
        listener(socket.connected);

        return () => undefined;
    },
}));

const sample = (overrides: Partial<ServerStats> = {}): ServerStats => ({
    time: 1_790_000_000_000,
    status: 'running',
    uptime: 93_784,
    cpu_percent: 25.6,
    cpus: 2,
    memory_bytes: 1024 ** 3,
    memory_total_bytes: 2 * 1024 ** 3,
    net_in: 1_550_000,
    net_out: 125_000,
    disk_read: 2_000_000,
    disk_write: null,
    ...overrides,
});

const fetchMock = vi.fn();

const answer = (stats: ServerStats | null, history: unknown[] = []) =>
    fetchMock.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ stats, history }),
    });

const mountCards = (portSpeedMbps: number | null = 100) =>
    mount(ServerStatsCards, {
        props: { serverUuid: 'server-uuid', portSpeedMbps },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('ServerStatsCards', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(1_790_000_005_000);
        vi.stubGlobal('fetch', fetchMock);
        fetchMock.mockReset();
        Object.assign(socket, {
            enabled: false,
            connected: false,
            pushed: null,
            stateListener: null,
            left: [],
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('shows the figures and the last hour on opening', async () => {
        answer(sample(), [{ time: 1_789_999_000_000, cpu_percent: 10 }]);
        const wrapper = mountCards();
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).toContain('history=1');
        expect(wrapper.get('[data-test="stats-cpu"]').text()).toContain(
            '25.6 %',
        );
        expect(wrapper.get('[data-test="stats-cpu"]').text()).toContain(
            'of :count vCPUs',
        );
        expect(wrapper.get('[data-test="stats-memory"]').text()).toContain(
            '1 GiB',
        );
        // Bytes per second shown in bits, like port speeds.
        expect(wrapper.get('[data-test="stats-network"]').text()).toContain(
            '12.4 Mbps',
        );
        expect(wrapper.get('[data-test="stats-network"]').text()).toContain(
            '1 Mbps',
        );
        // No rate without two samples.
        expect(wrapper.get('[data-test="stats-disk"]').text()).toContain('—');
        expect(wrapper.get('[data-test="stats-disk"]').text()).toContain(
            '2 MB/s',
        );
        expect(wrapper.text()).toContain('Up :time');
    });

    it('polls every 10 seconds without a socket', async () => {
        answer(sample());
        const wrapper = mountCards();
        await flushPromises();

        expect(wrapper.find('[data-test="stats-polling"]').exists()).toBe(true);

        answer(sample({ time: 1_790_000_010_000, cpu_percent: 80 }));
        await vi.advanceTimersByTimeAsync(POLL_MS);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][0]).not.toContain('history');
        expect(wrapper.get('[data-test="stats-cpu"]').text()).toContain('80 %');

        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(POLL_MS * 3);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('takes pushed figures and only asks to stay watched while connected', async () => {
        socket.enabled = true;
        socket.connected = true;
        answer(sample());
        const wrapper = mountCards();
        await flushPromises();

        expect(wrapper.find('[data-test="stats-live"]').exists()).toBe(true);

        socket.pushed?.(sample({ time: 1_790_000_010_000, cpu_percent: 42 }));
        await flushPromises();
        expect(wrapper.get('[data-test="stats-cpu"]').text()).toContain('42 %');

        // An older sample (e.g. a late answer) never replaces a newer one.
        socket.pushed?.(sample({ time: 1_790_000_001_000, cpu_percent: 1 }));
        await flushPromises();
        expect(wrapper.get('[data-test="stats-cpu"]').text()).toContain('42 %');

        await vi.advanceTimersByTimeAsync(POLL_MS);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        answer(sample({ time: 1_790_000_030_000 }));
        await vi.advanceTimersByTimeAsync(WATCH_MS - POLL_MS);
        expect(fetchMock).toHaveBeenCalledTimes(2);

        wrapper.unmount();
        expect(socket.left).toEqual(['admin.servers.server-uuid']);
    });

    it('falls back to polling when the socket drops', async () => {
        socket.enabled = true;
        socket.connected = true;
        answer(sample());
        const wrapper = mountCards();
        await flushPromises();

        socket.stateListener?.(false);
        await flushPromises();
        expect(wrapper.find('[data-test="stats-polling"]').exists()).toBe(true);

        answer(sample({ time: 1_790_000_010_000 }));
        await vi.advanceTimersByTimeAsync(POLL_MS);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('says when Proxmox gave no figures', async () => {
        answer(null);
        const wrapper = mountCards();
        await flushPromises();

        expect(wrapper.find('[data-test="stats-unavailable"]').exists()).toBe(
            true,
        );
    });

    it('marks figures as old when the panel stops answering', async () => {
        answer(sample());
        const wrapper = mountCards();
        await flushPromises();

        fetchMock.mockResolvedValue({ ok: false, status: 503 });
        await vi.advanceTimersByTimeAsync(POLL_MS);

        expect(wrapper.find('[data-test="stats-stale"]').exists()).toBe(true);
    });
});
