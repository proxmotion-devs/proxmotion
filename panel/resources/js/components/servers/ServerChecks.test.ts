import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import ServerChecks from '@/components/servers/ServerChecks.vue';
import type { ServerVerification } from '@/types/admin';

const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: (...args: unknown[]) => visit(...args) },
    usePage: () => ({ props: { locale: 'en' } }),
}));

// Replaces :placeholders like laravel-vue-i18n does.
const $t = (key: string, params: Record<string, string> = {}) =>
    Object.entries(params).reduce(
        (text, [name, value]) => text.replace(`:${name}`, value),
        key,
    );

const verification: ServerVerification = {
    checked_at: '2026-10-20T10:00:00+00:00',
    checks: [
        { key: 'addresses', status: 'passed', params: {}, detail: null },
        {
            key: 'gateway',
            status: 'failed',
            params: { address: '203.0.113.1' },
            detail: null,
        },
        {
            key: 'hostname',
            status: 'failed',
            params: { hostname: 'web1.example.com', actual: 'localhost' },
            detail: 'The server reports :actual.',
        },
        {
            key: 'disk',
            status: 'skipped',
            params: { size: '40 GiB' },
            detail: null,
        },
    ],
};

const render = (props: {
    verification: ServerVerification | null;
    action?: { url: string; method: 'post' };
}) => mount(ServerChecks, { props, global: { mocks: { $t } } });

describe('ServerChecks', () => {
    it('lists each check with its outcome and details', () => {
        const wrapper = render({ verification });

        expect(
            wrapper
                .findAll('li')
                .map((row) => [row.attributes('data-test'), row.text()]),
        ).toEqual([
            ['check-passed', 'IP addresses on the network interface'],
            ['check-failed', 'Gateway 203.0.113.1 answers'],
            [
                'check-failed',
                'Hostname is web1.example.comThe server reports localhost.',
            ],
            ['check-skipped', 'Root file system has 40 GiB'],
        ]);
    });

    it('says when the server was never checked', () => {
        const wrapper = render({ verification: null });

        expect(wrapper.text()).toContain('Not checked yet.');
        expect(wrapper.find('li').exists()).toBe(false);
        expect(wrapper.find('[data-test="server-check-again"]').exists()).toBe(
            false,
        );
    });

    it('checks again through the given action', async () => {
        const action = {
            url: '/admin/servers/1/verify',
            method: 'post' as const,
        };

        await render({ verification, action })
            .find('[data-test="server-check-again"]')
            .trigger('click');

        expect(visit).toHaveBeenCalledWith(action, expect.any(Object));
    });
});
