import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import ServerIdentity from '@/components/servers/ServerIdentity.vue';
import type { ServerAddress } from '@/types/admin';

const address = (
    value: string,
    version: 4 | 6,
    primary: boolean,
): ServerAddress => ({
    address: value,
    version,
    primary,
    pool: { uuid: 'pool', name: 'Pool' },
    ptr: null,
    ptr_pending: false,
});

type Identity = InstanceType<typeof ServerIdentity>['$props']['server'];

const server: Identity = {
    hostname: 'web1.example.com',
    vm_name: null,
    addresses: [
        address('203.0.113.5', 4, true),
        address('203.0.113.6', 4, false),
        address('2001:db8::5', 6, true),
    ],
    billing_system: 'WHMCS',
    external_id: '4711',
    external_client_id: '88',
    location: 'Istanbul',
    vmid: 101,
    node: 'pve1',
    os_template: 'Debian 13',
};

const render = (overrides: Partial<Identity> = {}) =>
    mount(ServerIdentity, {
        props: { server: { ...server, ...overrides } },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('ServerIdentity', () => {
    it('leads with the primary addresses, billing IDs and location', () => {
        const wrapper = render();
        const addresses = wrapper
            .findAll('[data-test="server-identity-address"]')
            .map((item) => item.find('dd').text());

        // Only the primary address of each version.
        expect(addresses).toEqual(['203.0.113.5', '2001:db8::5']);
        expect(
            wrapper.find('[data-test="server-identity-billing"]').text(),
        ).toContain('WHMCS');
        expect(
            wrapper.find('[data-test="server-identity-billing"]').text(),
        ).toContain('Service 4711');
        expect(
            wrapper.find('[data-test="server-identity-billing"]').text(),
        ).toContain('Customer 88');
        expect(wrapper.text()).toContain('Istanbul');
    });

    it('keeps the Proxmox side last', () => {
        const text = render().text();

        expect(text.indexOf('VMID 101')).toBeGreaterThan(
            text.indexOf('Istanbul'),
        );
        expect(text).toContain('pve1');
        expect(text).toContain('Debian 13');
    });

    it('says when no billing system owns the server', () => {
        expect(
            render({
                billing_system: null,
                external_id: null,
                external_client_id: null,
            })
                .find('[data-test="server-identity-billing"]')
                .text(),
        ).toContain('No billing system');
    });

    it('names the hostname when the title is a generated address', () => {
        expect(
            render({ vm_name: 'ip-203-0-113-5.example.com' }).text(),
        ).toContain('web1.example.com');
        expect(render().text()).not.toContain('web1.example.com');
    });
});
