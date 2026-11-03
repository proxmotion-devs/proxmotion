import { flushPromises, mount } from '@vue/test-utils';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import UsageHistorySection from '@/components/servers/UsageHistorySection.vue';
import type { UsageHistory } from '@/types/admin';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en' } }),
}));

const fetchMock = vi.fn();

const history = (overrides: Partial<UsageHistory> = {}): UsageHistory => ({
    range: 'day',
    interval_seconds: 300,
    from: '2026-10-14T12:00:00+00:00',
    to: '2026-10-15T12:00:00+00:00',
    points: [
        {
            time: '2026-10-15T11:50:00+00:00',
            cpu_percent: 12.5,
            memory_percent: 40,
            net_in_rate: 1000,
            net_out_rate: 500,
            disk_read_rate: null,
            disk_write_rate: 0,
            in_bytes: 300_000,
            out_bytes: 150_000,
        },
        {
            time: '2026-10-15T11:55:00+00:00',
            cpu_percent: 20,
            memory_percent: 41,
            net_in_rate: 2000,
            net_out_rate: 1000,
            disk_read_rate: null,
            disk_write_rate: 0,
            in_bytes: 600_000,
            out_bytes: 300_000,
        },
    ],
    traffic: {
        interval: 'hour',
        buckets: [
            {
                start: '2026-10-15T11:00:00+00:00',
                end: '2026-10-15T12:00:00+00:00',
                in_bytes: 2_000_000_000,
                out_bytes: 500_000_000,
                counted_bytes: 2_500_000_000,
            },
        ],
    },
    current: {
        period_start: '2026-10-01T00:00:00+00:00',
        period_end: '2026-11-01T00:00:00+00:00',
        in_bytes: 0,
        out_bytes: 0,
        counted_bytes: 0,
        quota_bytes: null,
    },
    ...overrides,
});

const answer = (body: UsageHistory, ok = true) =>
    fetchMock.mockResolvedValueOnce({
        ok,
        status: ok ? 200 : 500,
        json: async () => body,
    });

const mountSection = () =>
    mount(UsageHistorySection, {
        props: { serverUuid: 'server-uuid' },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('UsageHistorySection', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', fetchMock);
        fetchMock.mockReset();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('shows the last day with its traffic', async () => {
        answer(history());
        const wrapper = mountSection();
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).toContain('range=day');
        expect(wrapper.findAll('path').length).toBeGreaterThan(0);
        expect(wrapper.findAll('rect')).toHaveLength(2);
        expect(wrapper.get('[data-test="usage-traffic"]').text()).toContain(
            'Traffic per hour',
        );
        expect(wrapper.get('[data-test="usage-traffic-total"]').text()).toBe(
            'In :in · out :out',
        );
    });

    it('asks for another range when one is chosen', async () => {
        answer(history());
        const wrapper = mountSection();
        await flushPromises();

        answer(history({ range: 'year', interval_seconds: 86400 }));
        await wrapper.get('[data-test="usage-range-year"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls[1][0]).toContain('range=year');
        expect(
            wrapper
                .get('[data-test="usage-range-year"]')
                .attributes('aria-pressed'),
        ).toBe('true');
    });

    it('says when nothing was recorded or the request failed', async () => {
        answer(history({ points: [] }));
        const wrapper = mountSection();
        await flushPromises();

        expect(wrapper.find('[data-test="usage-empty"]').exists()).toBe(true);

        answer(history(), false);
        await wrapper.get('[data-test="usage-range-week"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="usage-failed"]').exists()).toBe(true);
    });
});
