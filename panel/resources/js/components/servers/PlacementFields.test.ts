import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import PlacementFields from '@/components/servers/PlacementFields.vue';
import type { PlacementNode } from '@/types/admin';

const node = (
    value: string,
    overrides: Partial<PlacementNode> = {},
): PlacementNode => ({
    value,
    label: value,
    location: 'ist',
    cluster: 'c1',
    placement_groups: [],
    online: true,
    maintenance: false,
    capacity: { cpu_cores: null, memory_mib: null, servers: null },
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
    node('pve1'),
    node('pve2', { maintenance: true }),
    node('ams1', { location: 'ams' }),
];

const groups = [
    { value: 'nvme', label: 'NVMe', location: 'ist' },
    { value: 'hdd', label: 'HDD', location: 'ist' },
    { value: 'ams-nvme', label: 'NVMe', location: 'ams' },
];

const mountFields = (
    chosen: {
        placement?: 'group' | 'node';
        node?: string;
        group?: string;
    } = {},
    groupsOfLocation = groups,
) => {
    // Deferred: the component already emits while it is mounted.
    const later = (props: Record<string, unknown>) =>
        void Promise.resolve().then(() => wrapper.setProps(props));

    const wrapper = mount(PlacementFields, {
        props: {
            nodes,
            groups: groupsOfLocation,
            location: 'ist',
            errors: {},
            placement: chosen.placement ?? 'node',
            group: chosen.group ?? null,
            node: chosen.node ?? null,
            storage: '',
            'onUpdate:placement': (value: string | null) =>
                later({ placement: value }),
            'onUpdate:group': (value: string | null) => later({ group: value }),
            'onUpdate:node': (value: string | null) => later({ node: value }),
            'onUpdate:storage': (value: string) => later({ storage: value }),
        },
        global: { mocks: { $t: (key: string) => key } },
    });

    return wrapper;
};

const values = (wrapper: ReturnType<typeof mountFields>, select: string) =>
    wrapper
        .findAll(`${select} option`)
        .map((option) => option.element as HTMLOptionElement)
        // Leave out the placeholder.
        .filter((option) => !option.disabled)
        .map((option) => option.value);

describe('PlacementFields', () => {
    it('offers only a placement group or a single node', () => {
        expect(values(mountFields(), '#placement')).toEqual(['group', 'node']);
    });

    it('offers the nodes of the location, marking those in maintenance', () => {
        const wrapper = mountFields();

        expect(values(wrapper, '#node')).toEqual(['pve1', 'pve2']);
        expect(wrapper.find('#node option[value="pve2"]').text()).toContain(
            'maintenance',
        );
    });

    it('offers the groups of the location and picks the first', async () => {
        const wrapper = mountFields({ placement: 'group' });
        await flushPromises();

        expect(values(wrapper, '#placement_group')).toEqual(['nvme', 'hdd']);
        expect(wrapper.props('group')).toBe('nvme');
    });

    it('moves to a node of the new location', async () => {
        const wrapper = mountFields({ node: 'pve1' });
        await flushPromises();

        await wrapper.setProps({ location: 'ams' });
        await flushPromises();
        expect(wrapper.props('node')).toBe('ams1');
    });

    it('starts with a single node when the location has no group', async () => {
        const wrapper = mountFields({ placement: 'group' }, []);
        await flushPromises();

        expect(wrapper.props('placement')).toBe('node');
        expect(
            mountFields({ placement: 'group' }).emitted('update:placement'),
        ).toBeUndefined();
    });
});
