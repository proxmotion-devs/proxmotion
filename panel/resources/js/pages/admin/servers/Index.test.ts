import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import Index from '@/pages/admin/servers/Index.vue';
import type { ServerListItem } from '@/types/admin';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: {
        props: ['href'],
        template: '<a :href="href?.url ?? href"><slot /></a>',
    },
    router: { visit: vi.fn() },
    usePage: () => ({ props: { locale: 'en' } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (key: string) => key }));

const server = (overrides: Partial<ServerListItem> = {}): ServerListItem => ({
    uuid: 'server-1',
    hostname: 'web1.example.com',
    vm_name: null,
    status: 'active',
    vmid: 101,
    billing_system: 'WHMCS',
    external_id: '4711',
    external_client_id: null,
    node: 'pve1',
    location: 'Istanbul',
    package: 'VPS S',
    addresses: ['203.0.113.5', '2001:db8::5'],
    unprotected: false,
    ...overrides,
});

const render = (servers: ServerListItem[], search = '') =>
    mount(Index, {
        props: {
            servers: {
                data: servers,
                current_page: 1,
                last_page: 1,
                total: servers.length,
                links: [],
            },
            showTerminated: false,
            status: null,
            search,
        },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('servers/Index', () => {
    it('lists each server on phones as one row with what a ticket names', () => {
        const rows = render([server()]).find('[data-test="server-rows"]');
        const row = rows.find('a');

        expect(row.attributes('href')).toBe('/admin/servers/server-1');
        expect(row.text()).toContain('web1.example.com');
        expect(row.text()).toContain('Active');
        // The primary address only, and the billing service ID.
        expect(row.text()).toContain('203.0.113.5');
        expect(row.text()).not.toContain('2001:db8::5');
        expect(row.text()).toContain('4711');
        expect(row.text()).toContain('pve1');
    });

    it('says why the phone list is empty', () => {
        expect(
            render([], 'nothing').find('[data-test="server-rows"]').text(),
        ).toBe('No servers match the search.');
    });
});
