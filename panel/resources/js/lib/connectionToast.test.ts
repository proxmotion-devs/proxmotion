import { describe, expect, it, vi } from 'vite-plus/test';
import { initializeConnectionToast } from '@/lib/connectionToast';

const { handlers, error } = vi.hoisted(() => ({
    handlers: {} as Record<string, () => void>,
    error: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (event: string, handler: () => void) => (handlers[event] = handler),
    },
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (key: string) => key }));
vi.mock('vue-sonner', () => ({ toast: { error } }));

describe('initializeConnectionToast', () => {
    it('says once per 30 seconds that the panel could not be reached', () => {
        let time = 1_000;
        initializeConnectionToast(() => time);

        handlers.networkError();
        time += 10_000;
        handlers.networkError();
        expect(error).toHaveBeenCalledTimes(1);
        expect(error).toHaveBeenCalledWith(
            'The panel could not be reached. Check your connection and try again.',
        );

        time += 25_000;
        handlers.networkError();
        expect(error).toHaveBeenCalledTimes(2);
    });
});
