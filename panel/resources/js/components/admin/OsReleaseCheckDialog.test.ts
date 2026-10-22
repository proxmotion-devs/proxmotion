import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import OsReleaseCheckDialog from '@/components/admin/OsReleaseCheckDialog.vue';
import type { OsReleaseResult } from '@/lib/osTemplates';
import type { OsTemplate } from '@/types/admin';

vi.mock('laravel-vue-i18n', () => ({ trans: (key: string) => key }));

const template: OsTemplate = {
    uuid: 'tpl-1',
    name: 'Debian 12',
    operating_system_version_id: 1,
    distribution: 'debian',
    version: '12',
    platform: 'linux',
    is_active: true,
    clone_mode: 'full',
    sort_order: 0,
    proxmox_template_ids: [],
};

const result: OsReleaseResult = {
    server: 'srv-1',
    servers: [{ value: 'srv-1', label: 'vps1.example.com' }],
    os_release: {
        id: 'pardus',
        id_like: ['debian'],
        version_id: '23.4',
        pretty_name: 'Pardus 23.4',
    },
    template_system: 'Debian 12',
    server_system: 'Pardus 23.4',
    version_matches: false,
};

const passThrough = { template: '<div><slot /></div>' };

const mountDialog = () =>
    mount(OsReleaseCheckDialog, {
        props: {
            open: true,
            'onUpdate:open': () => {},
            template,
        },
        global: {
            mocks: { $t: (key: string) => key },
            stubs: {
                Dialog: passThrough,
                DialogContent: passThrough,
                DialogHeader: passThrough,
                DialogTitle: passThrough,
                DialogDescription: passThrough,
                DialogFooter: passThrough,
                Link: {
                    props: ['href'],
                    template: '<a :href="href.url ?? href"><slot /></a>',
                },
            },
        },
    });

describe('OsReleaseCheckDialog', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('shows what the server runs next to the template', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response(JSON.stringify(result))),
        );
        const wrapper = mountDialog();
        await flushPromises();

        expect(wrapper.text()).toContain('ID=pardus');
        expect(wrapper.text()).toContain('Debian 12');
        expect(wrapper.text()).toContain('Pardus 23.4');
        expect(wrapper.find('[data-test="os-release-mismatch"]').exists()).toBe(
            true,
        );
    });

    it('shows why a server could not be read', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response(
                        JSON.stringify({ message: 'The agent is down.' }),
                        { status: 422 },
                    ),
            ),
        );
        const wrapper = mountDialog();
        await flushPromises();

        expect(wrapper.get('[data-test="os-release-error"]').text()).toContain(
            'The agent is down.',
        );
    });

    it('offers to create a server when none runs the template', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response(
                        JSON.stringify({
                            message: 'No server.',
                            no_server: true,
                        }),
                        { status: 422 },
                    ),
            ),
        );
        const wrapper = mountDialog();
        await flushPromises();

        expect(
            wrapper
                .get('[data-test="os-release-create-server"]')
                .attributes('href'),
        ).toContain('os_template=tpl-1');
    });
});
