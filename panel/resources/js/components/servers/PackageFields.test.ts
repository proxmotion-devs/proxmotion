import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import PackageFields from '@/components/servers/PackageFields.vue';
import type {
    PackageOption,
    PlacementGroupOption,
    PlacementNode,
} from '@/types/admin';

const pkg = (overrides: Partial<PackageOption> = {}): PackageOption => ({
    value: 'small',
    label: 'Small',
    network_models: ['bridged'],
    placement_groups: [],
    cpu_cores: 2,
    memory_mib: 4096,
    disk_gib: 40,
    ipv4_count: 1,
    ipv6_count: 0,
    port_speed_mbps: null,
    traffic_gb: 2000,
    ...overrides,
});

const groups: PlacementGroupOption[] = [
    { value: 'nvme', label: 'NVMe', location: 'ist', storages: [] },
];

const nodes: PlacementNode[] = [
    {
        value: 'pve1',
        label: 'pve1',
        location: 'ist',
        cluster: 'c1',
        placement_groups: ['nvme'],
        online: true,
        maintenance: false,
        capacity: { cpu_cores: 8, memory_mib: 8192, servers: null },
        storages: [
            {
                name: 'local-lvm',
                type: 'lvmthin',
                shared: false,
                use_for_servers: true,
                free_mib: 102400,
            },
        ],
    },
];

const mountFields = (packages: PackageOption[], location = 'ist') =>
    mount(PackageFields, {
        props: {
            packages,
            groups,
            nodes,
            location,
            errors: {},
            package: 'small',
        },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('PackageFields', () => {
    it('shows the package resources read-only', () => {
        const wrapper = mountFields([pkg()]);
        const values = wrapper.find('[data-test="package-values"]');

        expect(values.text()).toContain('4 GiB');
        expect(values.text()).toContain('40 GiB');
        expect(values.text()).toContain('unlimited');
        expect(values.text()).toContain('2000 GB');
        expect(wrapper.find('input').exists()).toBe(false);
    });

    it('places a package without groups on the best node of the location', () => {
        const placement = mountFields([pkg()]).find(
            '[data-test="package-placement"]',
        );

        expect(placement.text()).toContain(
            'Placed on the best node of the location.',
        );
        expect(placement.text()).toContain(
            'It fits on :fitting of :total nodes.',
        );
    });

    it('names the package groups of the location, or says there are none', () => {
        const withGroups = [pkg({ placement_groups: ['nvme'] })];

        expect(
            mountFields(withGroups)
                .find('[data-test="package-placement"]')
                .text(),
        ).toContain('Placed on the best node of NVMe.');
        expect(
            mountFields(withGroups, 'ams')
                .find('[data-test="package-placement"]')
                .text(),
        ).toContain(
            'None of this package’s placement groups is in this location.',
        );
    });

    it('warns when the server fits on no node', () => {
        const wrapper = mountFields([pkg({ memory_mib: 65536 })]);

        expect(wrapper.text()).toContain(
            'This server fits on none of the :total nodes it may go to.',
        );
    });
});
