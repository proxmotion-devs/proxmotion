import { describe, expect, it, vi } from 'vite-plus/test';
import { usePendingVisit } from '@/lib/pendingVisit';

type Callbacks = {
    onStart: (event: unknown) => void;
    onFinish: (event: unknown) => void;
    preserveScroll?: boolean;
};

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { visit } }));

const lastOptions = () => visit.mock.calls.at(-1)?.[1] as Callbacks;

describe('usePendingVisit', () => {
    it('marks the request that runs and ignores clicks until it finished', () => {
        const { pending, busy, visit: send } = usePendingVisit();
        const onFinish = vi.fn();

        send('ping', '/admin/webhooks/1/ping', { onFinish });
        expect(lastOptions().preserveScroll).toBe(true);
        lastOptions().onStart({});

        expect(pending.value).toBe('ping');
        expect(busy('ping')).toBe(true);
        expect(busy('secret')).toBe(false);

        send('ping', '/admin/webhooks/1/ping');
        send('secret', '/admin/webhooks/1/secret');
        expect(visit).toHaveBeenCalledTimes(1);

        lastOptions().onFinish({});
        expect(pending.value).toBeNull();
        expect(onFinish).toHaveBeenCalledOnce();

        send('secret', '/admin/webhooks/1/secret');
        expect(visit).toHaveBeenCalledTimes(2);
    });
});
