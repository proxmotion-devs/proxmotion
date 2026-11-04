import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import Breadcrumbs from '@/components/Breadcrumbs.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

const hostname = `${'very-long-label-'.repeat(12)}example.com`;

describe('Breadcrumbs', () => {
    it('shortens long names and keeps them whole on hover', () => {
        const wrapper = mount(Breadcrumbs, {
            props: {
                breadcrumbs: [
                    { title: 'Servers', href: '/admin/servers' },
                    { title: hostname, href: '/admin/servers/1' },
                ],
            },
            global: { mocks: { $t: (key: string) => key } },
        });

        const link = wrapper.get('a');
        expect(link.attributes('title')).toBe('Servers');
        expect(link.classes()).toContain('truncate');

        const current = wrapper.get('[aria-current="page"]');
        expect(current.attributes('title')).toBe(hostname);
        expect(current.classes()).toContain('truncate');
        expect(current.text()).toBe(hostname);
    });
});
