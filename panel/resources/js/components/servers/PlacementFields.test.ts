import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import PlacementFields from '@/components/servers/PlacementFields.vue';
import type { PlacementNode } from '@/types/admin';

const node = (
    value: string,
    cluster: string,
    overrides: Partial<PlacementNode> = {},
): PlacementNode => ({
    value,
    label: value,
    location: 'ist',
    cluster,
    online: true,
    maintenance: false,
    storages: [
        {
            name: 'local-lvm',
            type: 'lvmthin',
            shared: false,
            use_for_servers: true,
            free_mib: 102400,
        },
    ],
    ...overrides,
});

const nodes = [
    node('pve1', 'epyc'),
    node('pve2', 'xeon'),
    node('ams1', 'epyc', { location: 'ams' }),
];

const mountFields = (clusters: string[], chosen: string | null = null) => {
    const wrapper = mount(PlacementFields, {
        props: {
            nodes,
            location: 'ist',
            clusters,
            errors: {},
            placement: 'node',
            node: chosen,
            storage: '',
            'onUpdate:node': (value: string | null) =>
                // Deferred: the component already emits while it is mounted.
                void Promise.resolve().then(() =>
                    wrapper.setProps({ node: value }),
                ),
            'onUpdate:storage': (value: string) =>
                void Promise.resolve().then(() =>
                    wrapper.setProps({ storage: value }),
                ),
        },
        global: { mocks: { $t: (key: string) => key } },
    });

    return wrapper;
};

const nodeOptions = (wrapper: ReturnType<typeof mountFields>) =>
    wrapper
        .findAll('#node option')
        .map((option) => option.element as HTMLOptionElement)
        // Leave out the placeholder.
        .filter((option) =>
            nodes.some((candidate) => candidate.value === option.value),
        )
        .map((option) => ({ value: option.value, disabled: option.disabled }));

describe('PlacementFields', () => {
    it('offers every node of the location when the package names no clusters', () => {
        expect(nodeOptions(mountFields([]))).toEqual([
            { value: 'pve1', disabled: false },
            { value: 'pve2', disabled: false },
        ]);
    });

    it('marks nodes outside the package clusters and does not let them be chosen', async () => {
        const wrapper = mountFields(['xeon'], 'pve1');
        await flushPromises();

        expect(nodeOptions(wrapper)).toEqual([
            { value: 'pve1', disabled: true },
            { value: 'pve2', disabled: false },
        ]);
        expect(wrapper.find('#node option[value="pve1"]').text()).toContain(
            'not allowed by this package',
        );
        expect(wrapper.props('node')).toBe('pve2');
    });

    it('moves off a node the newly chosen package does not allow', async () => {
        const wrapper = mountFields([], 'pve2');
        await flushPromises();
        expect(wrapper.props('node')).toBe('pve2');

        await wrapper.setProps({ clusters: ['epyc'] });
        await flushPromises();
        expect(wrapper.props('node')).toBe('pve1');
    });

    it('chooses no node when the package allows none in the location', async () => {
        const wrapper = mountFields(['arm'], 'pve1');
        await flushPromises();

        expect(wrapper.props('node')).toBeNull();
    });
});
