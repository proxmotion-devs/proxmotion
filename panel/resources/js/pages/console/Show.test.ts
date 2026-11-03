import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import Show from '@/pages/console/Show.vue';
import type { Branding } from '@/types/ui';

const { branding } = vi.hoisted(() => ({
    branding: { value: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    router: { reload: vi.fn() },
    usePage: () => ({ props: { branding: branding.value } }),
}));

const mountConsole = (
    overrides: Partial<Branding>,
    backUrl: string | null = null,
) => {
    branding.value = {
        name: 'Example Cloud',
        color: null,
        color_foreground: null,
        logo_url: null,
        logo_dark_url: null,
        favicon_url: null,
        support_email: null,
        support_url: null,
        ...overrides,
    };

    // No console: the page shows the error and never loads noVNC.
    return mount(Show, {
        props: {
            server: { uuid: 'abc', hostname: 'vps1.example.com' },
            console: null,
            error: 'This console link has already been used or has expired.',
            idleMinutes: 15,
            backUrl,
        },
        global: {
            mocks: {
                $t: (key: string, replace: Record<string, string> = {}) =>
                    key.replace(':email', replace.email ?? ''),
            },
        },
    });
};

describe('Console page branding', () => {
    it('shows the name and the support page to customers in a new tab', () => {
        const wrapper = mountConsole({
            support_email: 'help@example.com',
            support_url: 'https://help.example.com',
        });

        expect(wrapper.find('[data-test="console-brand"]').text()).toContain(
            'Example Cloud',
        );

        const button = wrapper.find('[data-test="console-support"]');
        expect(button.attributes('href')).toBe('https://help.example.com');
        expect(button.attributes('target')).toBe('_blank');
        expect(
            wrapper
                .find('[data-test="console-error-support"]')
                .attributes('href'),
        ).toBe('https://help.example.com');
    });

    it('opens the mail client for a support email', () => {
        const wrapper = mountConsole({ support_email: 'help@example.com' });

        const link = wrapper.find('[data-test="console-error-support"]');
        expect(link.attributes('href')).toBe('mailto:help@example.com');
        expect(link.attributes('target')).toBeUndefined();
        expect(link.text()).toBe('Need help? Write to help@example.com.');
    });

    it('offers no customer support to administrators', () => {
        const wrapper = mountConsole(
            { support_url: 'https://help.example.com' },
            '/admin/servers/abc',
        );

        expect(wrapper.find('[data-test="console-support"]').exists()).toBe(
            false,
        );
        expect(
            wrapper.find('[data-test="console-error-support"]').exists(),
        ).toBe(false);
    });
});
