import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import MoveDialog from '@/components/servers/MoveDialog.vue';
import type { MoveNodeOption, MoveOptions } from '@/types/admin';

const { submit, transformed } = vi.hoisted(() => ({
    submit: vi.fn(),
    transformed: { value: null as Record<string, unknown> | null },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        useForm: (data: Record<string, unknown>) => {
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                clearErrors: () => undefined,
                reset: () => undefined,
                transform: (
                    callback: (
                        values: Record<string, unknown>,
                    ) => Record<string, unknown>,
                ) => ({
                    submit: (...args: unknown[]) => {
                        transformed.value = callback({ ...data, ...form });
                        submit(...args);
                    },
                }),
            });

            return form;
        },
    };
});

vi.mock('laravel-vue-i18n', () => ({ trans: (key: string) => key }));

const node = (
    value: string,
    overrides: Partial<MoveNodeOption> = {},
): MoveNodeOption => ({
    value,
    label: value,
    location: 'ist',
    location_name: 'Istanbul',
    cluster: 'a',
    online: true,
    maintenance: false,
    refusal: null,
    storages: [
        {
            name: 'local-lvm',
            shared: false,
            use_for_servers: true,
            free_mib: 1024,
        },
    ],
    ...overrides,
});

const options = (overrides: Partial<MoveOptions> = {}): MoveOptions => ({
    refusal: null,
    cross_cluster: true,
    routed: false,
    location: 'ist',
    cluster: 'a',
    nodes: [
        node('pve2'),
        node('pveb1', { cluster: 'b' }),
        node('pvec1', {
            cluster: 'c',
            location: 'fra',
            location_name: 'Frankfurt',
        }),
    ],
    placementGroups: [],
    ipv4Pools: [
        {
            value: 'fra-pool',
            label: 'Frankfurt (198.51.100.0/29)',
            location: 'fra',
            free: 5,
        },
    ],
    moves: [],
    ...overrides,
});

const mountDialog = (moveOptions: MoveOptions = options()) =>
    mount(MoveDialog, {
        props: {
            action: { url: '/admin/servers/x/move', method: 'post' },
            options: moveOptions,
            hostname: 'web1.example.com',
        },
        global: {
            mocks: { $t: (key: string) => key },
            stubs: {
                FormDialog: {
                    emits: ['submit'],
                    template:
                        '<form @submit.prevent="$emit(\'submit\')"><slot /></form>',
                },
            },
        },
    });

const chooseNode = async (
    wrapper: ReturnType<typeof mountDialog>,
    value: string,
) => {
    await wrapper.find('[data-test="server-move"]').trigger('click');
    await wrapper.find('#move_node').setValue(value);
    await flushPromises();
};

describe('MoveDialog', () => {
    beforeEach(() => {
        submit.mockReset();
        transformed.value = null;
    });

    it('moves within the cluster without asking for the hostname', async () => {
        const wrapper = mountDialog();
        await chooseNode(wrapper, 'pve2');

        expect(wrapper.find('[data-test="move-kind"]').text()).toContain(
            'To another node of the same cluster',
        );
        expect(wrapper.find('#move_confirm_hostname').exists()).toBe(false);
        expect(wrapper.find('#move_pool').exists()).toBe(false);

        await wrapper.find('form').trigger('submit');

        expect(submit).toHaveBeenCalledOnce();
        expect(transformed.value).toMatchObject({
            target: 'node',
            node: 'pve2',
            storage: null,
            ipv4_pool: null,
        });
    });

    it('asks for the hostname when the server leaves its cluster', async () => {
        const wrapper = mountDialog();
        await chooseNode(wrapper, 'pveb1');

        expect(wrapper.find('[data-test="move-kind"]').text()).toContain(
            'To another cluster of this location',
        );
        expect(wrapper.find('#move_confirm_hostname').exists()).toBe(true);
    });

    it('offers the pools of another location for the new addresses', async () => {
        const wrapper = mountDialog();
        await chooseNode(wrapper, 'pvec1');

        expect(wrapper.find('[data-test="move-kind"]').text()).toContain(
            'To another location',
        );
        expect(wrapper.find('#move_pool').text()).toContain(
            'Frankfurt (198.51.100.0/29)',
        );
    });

    it('cannot be opened while the server cannot move', () => {
        const wrapper = mountDialog(
            options({
                refusal: 'Another task is still running for this server.',
            }),
        );

        expect(
            wrapper.find('[data-test="server-move"]').attributes('disabled'),
        ).toBeDefined();
    });

    it('asks to confirm routed addresses', async () => {
        const wrapper = mountDialog(options({ routed: true }));
        await chooseNode(wrapper, 'pve2');

        expect(wrapper.find('[data-test="move-routed"]').exists()).toBe(true);
    });
});
