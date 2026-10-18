import { flushPromises, mount } from '@vue/test-utils';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import AppLogDialog from '@/components/servers/AppLogDialog.vue';

const passThrough = { template: '<div><slot /></div>' };

const respond = (body: Record<string, unknown>) =>
    Promise.resolve(
        new Response(
            JSON.stringify({
                text: '',
                reset: false,
                cursor: 0,
                live: true,
                status: 'installing',
                progress: 0,
                step: null,
                ...body,
            }),
            { status: 200 },
        ),
    );

const mountDialog = () =>
    mount(AppLogDialog, {
        props: {
            open: false,
            heading: 'WireGuard log',
            logs: [{ value: 'log-1', label: 'Attempt 1' }],
        },
        global: {
            mocks: { $t: (key: string) => key },
            stubs: {
                Dialog: passThrough,
                DialogContent: passThrough,
                DialogHeader: passThrough,
                DialogTitle: passThrough,
                DialogDescription: passThrough,
            },
        },
    });

describe('AppLogDialog', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('follows a running script and stops once it ended', async () => {
        const fetch = vi
            .fn()
            .mockReturnValueOnce(
                respond({
                    text: 'one\n',
                    reset: true,
                    cursor: 4,
                    progress: 20,
                    step: 'Installing',
                }),
            )
            .mockReturnValueOnce(respond({ text: 'two\n', cursor: 8 }))
            .mockReturnValueOnce(
                respond({
                    text: 'one\ntwo\ndone\n',
                    reset: true,
                    cursor: 13,
                    live: false,
                }),
            );
        vi.stubGlobal('fetch', fetch);
        const wrapper = mountDialog();

        await wrapper.setProps({ open: true });
        await flushPromises();

        const log = () => wrapper.find('[data-test="app-log-text"]').text();
        expect(log()).toBe('one');
        expect(wrapper.find('[data-test="app-log-live"]').text()).toContain(
            '20%',
        );
        expect(fetch.mock.calls[0][0]).toBe('/admin/app-logs/log-1');

        await vi.advanceTimersByTimeAsync(2000);
        await flushPromises();
        expect(log()).toBe('one\ntwo');
        expect(fetch.mock.calls[1][0]).toBe('/admin/app-logs/log-1?cursor=4');

        await vi.advanceTimersByTimeAsync(2000);
        await flushPromises();
        expect(log()).toBe('one\ntwo\ndone');
        expect(wrapper.find('[data-test="app-log-live"]').exists()).toBe(false);

        await vi.advanceTimersByTimeAsync(10000);
        expect(fetch).toHaveBeenCalledTimes(3);
    });

    it('stops asking when it is closed', async () => {
        const fetch = vi.fn(() => respond({ text: 'one\n', reset: true }));
        vi.stubGlobal('fetch', fetch);
        const wrapper = mountDialog();

        await wrapper.setProps({ open: true });
        await flushPromises();
        await wrapper.setProps({ open: false });
        await vi.advanceTimersByTimeAsync(10000);

        expect(fetch).toHaveBeenCalledTimes(1);
    });
});
