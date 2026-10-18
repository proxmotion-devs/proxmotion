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
    placement_groups: [],
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
    node('pve1', 'epyc', { placement_groups: ['nvme'] }),
    node('pve2', 'xeon', { placement_groups: ['hdd'] }),
    node('ams1', 'epyc', { location: 'ams' }),
];

const groups = [
    { value: 'nvme', label: 'NVMe', location: 'ist' },
    { value: 'hdd', label: 'HDD', location: 'ist' },
    { value: 'ams-nvme', label: 'NVMe', location: 'ams' },
];

type Limits = { packageGroups?: string[] };

const mountFields = (
    limits: Limits,
    chosen: { node?: string; group?: string } = {},
) => {
    // Deferred: the component already emits while it is mounted.
    const later = (props: Record<string, unknown>) =>
        void Promise.resolve().then(() => wrapper.setProps(props));

    const wrapper = mount(PlacementFields, {
        props: {
            nodes,
            groups,
            location: 'ist',
            packageGroups: limits.packageGroups ?? [],
            errors: {},
            placement: 'node',
            group: chosen.group ?? null,
            node: chosen.node ?? null,
            storage: '',
            'onUpdate:group': (value: string | null) => later({ group: value }),
            'onUpdate:node': (value: string | null) => later({ node: value }),
            'onUpdate:storage': (value: string) => later({ storage: value }),
        },
        global: { mocks: { $t: (key: string) => key } },
    });

    return wrapper;
};

const options = (
    wrapper: ReturnType<typeof mountFields>,
    select: string,
    values: string[],
) =>
    wrapper
        .findAll(`${select} option`)
        .map((option) => option.element as HTMLOptionElement)
        // Leave out the placeholder.
        .filter((option) => values.includes(option.value))
        .map((option) => ({ value: option.value, disabled: option.disabled }));

const nodeOptions = (wrapper: ReturnType<typeof mountFields>) =>
    options(
        wrapper,
        '#node',
        nodes.map((candidate) => candidate.value),
    );

describe('PlacementFields', () => {
    it('offers every node of the location when the package names no groups', () => {
        expect(nodeOptions(mountFields({}))).toEqual([
            { value: 'pve1', disabled: false },
            { value: 'pve2', disabled: false },
        ]);
    });

    it('offers only a placement group or a single node', () => {
        expect(
            mountFields({})
                .findAll('#placement option')
                .map((option) => (option.element as HTMLOptionElement).value),
        ).toEqual(['group', 'node']);
    });

    it('starts with a single node when the location has no usable group', () => {
        const mountWith = (groupsOfLocation: typeof groups) =>
            mount(PlacementFields, {
                props: {
                    nodes,
                    groups: groupsOfLocation,
                    location: 'ist',
                    packageGroups: [],
                    errors: {},
                    placement: 'group',
                    group: null,
                    node: null,
                    storage: '',
                },
                global: { mocks: { $t: (key: string) => key } },
            });

        expect(mountWith([]).emitted('update:placement')?.[0]).toEqual([
            'node',
        ]);
        expect(mountWith(groups).emitted('update:placement')).toBeUndefined();
    });

    it('moves off a node the newly chosen package does not allow', async () => {
        const wrapper = mountFields({}, { node: 'pve2' });
        await flushPromises();
        expect(wrapper.props('node')).toBe('pve2');

        await wrapper.setProps({ packageGroups: ['nvme'] });
        await flushPromises();
        expect(wrapper.props('node')).toBe('pve1');
    });

    it('chooses no node when the package allows none in the location', async () => {
        const wrapper = mountFields(
            { packageGroups: ['arm'] },
            { node: 'pve1' },
        );
        await flushPromises();

        expect(wrapper.props('node')).toBeNull();
    });

    it('marks nodes outside the package placement groups and does not let them be chosen', async () => {
        const wrapper = mountFields(
            { packageGroups: ['hdd'] },
            { node: 'pve1' },
        );
        await flushPromises();

        expect(nodeOptions(wrapper)).toEqual([
            { value: 'pve1', disabled: true },
            { value: 'pve2', disabled: false },
        ]);
        expect(wrapper.props('node')).toBe('pve2');
    });

    it('offers the groups of the location and disables those the package does not allow', async () => {
        const wrapper = mountFields(
            { packageGroups: ['hdd'] },
            { group: 'nvme' },
        );
        await wrapper.setProps({ placement: 'group' });
        await flushPromises();

        expect(
            options(
                wrapper,
                '#placement_group',
                groups.map((group) => group.value),
            ),
        ).toEqual([
            { value: 'nvme', disabled: true },
            { value: 'hdd', disabled: false },
        ]);
        expect(wrapper.props('group')).toBe('hdd');
    });
});
