import { describe, expect, it } from 'vite-plus/test';
import {
    firstStepWithError,
    automaticFit,
    groupFit,
    groupStorages,
    makesTestServer,
    nodeProblems,
    nodeStorages,
    packageAllowing,
    templatesFor,
    WIZARD_STEPS,
} from '@/lib/serverWizard';
import type { PlacementGroupOption, PlacementNode } from '@/types/admin';

describe('packageAllowing', () => {
    const packages = [
        { value: 'small', os_templates: ['debian'] },
        { value: 'big', os_templates: [] },
        { value: 'win', os_templates: ['windows'] },
    ];

    it('returns the first package that names the template', () => {
        expect(packageAllowing(packages, 'windows')).toBe('big');
    });

    it('skips packages that name other templates', () => {
        expect(
            packageAllowing(packages.slice(0, 1), 'windows'),
        ).toBeUndefined();
    });

    it('takes a package naming no template', () => {
        expect(
            packageAllowing([{ value: 'any', os_templates: [] }], 'debian'),
        ).toBe('any');
    });
});

describe('templatesFor', () => {
    const templates = [
        { value: 'ubuntu', label: 'Ubuntu', active: true },
        { value: 'debian', label: 'Debian', active: true },
    ];

    it('lists the templates a package names', () => {
        expect(
            templatesFor(templates, { os_templates: ['debian'] }).map(
                (option) => option.value,
            ),
        ).toEqual(['debian']);
    });

    it('lists every template when the package names none or there is no package', () => {
        expect(templatesFor(templates, { os_templates: [] })).toEqual(
            templates,
        );
        expect(templatesFor(templates, undefined)).toEqual(templates);
    });
});

const storage = (name: string, freeMib: number, useForServers = true) => ({
    name,
    type: 'lvmthin',
    shared: false,
    use_for_servers: useForServers,
    free_mib: freeMib,
});

const node = (
    value: string,
    overrides: Partial<PlacementNode> = {},
): PlacementNode => ({
    value,
    label: value,
    location: 'ist',
    cluster: 'c1',
    placement_groups: ['nvme'],
    online: true,
    maintenance: false,
    capacity: { cpu_cores: 8, memory_mib: 8192, servers: null },
    storages: [storage('local-lvm', 51200), storage('nvme', 204800, false)],
    ...overrides,
});

const size = { cpu_cores: 2, memory_mib: 4096, disk_mib: 40960 };

const group = (storages: string[] = []): PlacementGroupOption => ({
    value: 'nvme',
    label: 'NVMe',
    location: 'ist',
    storages,
});

describe('firstStepWithError', () => {
    it('finds the step that owns a field, also for nested keys', () => {
        expect(firstStepWithError({ hostname: 'x' })).toBe(4);
        expect(
            firstStepWithError({ 'app_inputs.port': 'x', ssh_keys: 'y' }),
        ).toBe(2);
        expect(firstStepWithError({ 'ipv4_addresses.0': 'x' })).toBe(3);
        expect(firstStepWithError({ resource_mode: 'x' })).toBe(0);
        expect(firstStepWithError({ node: 'x', package: 'y' })).toBe(1);
        expect(firstStepWithError({ server: 'No capacity' })).toBe(
            WIZARD_STEPS.length - 1,
        );
        expect(firstStepWithError({})).toBeNull();
    });
});

describe('nodeProblems', () => {
    it('is empty when the server fits', () => {
        const pve1 = node('pve1');
        expect(nodeProblems(pve1, size, nodeStorages(pve1, ''))).toEqual([]);
    });

    it('names every limit the server exceeds', () => {
        const pve1 = node('pve1', {
            online: false,
            capacity: { cpu_cores: 1.5, memory_mib: 2048, servers: 0 },
        });

        expect(
            nodeProblems(pve1, { ...size, disk_mib: 999999 }, pve1.storages),
        ).toEqual(['offline', 'cpu', 'memory', 'servers', 'disk']);
    });

    it('ignores limits the node does not set', () => {
        const pve1 = node('pve1', {
            capacity: { cpu_cores: null, memory_mib: null, servers: null },
        });

        expect(
            nodeProblems(pve1, { ...size, cpu_cores: 512 }, pve1.storages),
        ).toEqual([]);
    });
});

describe('storages', () => {
    it('uses the chosen storage, or those marked for servers', () => {
        const pve1 = node('pve1');

        expect(nodeStorages(pve1, '').map((s) => s.name)).toEqual([
            'local-lvm',
        ]);
        expect(nodeStorages(pve1, 'nvme').map((s) => s.name)).toEqual(['nvme']);
    });

    it('uses the storages a group lists on the node', () => {
        const pve1 = node('pve1');

        expect(groupStorages(group(), pve1).map((s) => s.name)).toEqual([
            'local-lvm',
        ]);
        expect(
            groupStorages(group(['pve1/nvme', 'pve2/nvme']), pve1).map(
                (s) => s.name,
            ),
        ).toEqual(['nvme']);
    });
});

describe('groupFit', () => {
    it('counts the group nodes outside maintenance and those the server fits on', () => {
        const nodes = [
            node('pve1'),
            node('pve2', {
                capacity: { cpu_cores: 8, memory_mib: 1024, servers: null },
            }),
            node('pve3', { maintenance: true }),
            node('pve4', { placement_groups: ['hdd'] }),
        ];

        expect(groupFit(group(), nodes, size)).toEqual({
            total: 2,
            fitting: 1,
        });
        // Only pve2 lists a storage in the group, and pve2 has too little memory.
        expect(groupFit(group(['pve2/nvme']), nodes, size)).toEqual({
            total: 2,
            fitting: 0,
        });
    });
});

describe('automaticFit', () => {
    const nodes = [
        node('pve1'),
        node('pve2', {
            placement_groups: ['hdd'],
            capacity: { cpu_cores: 1, memory_mib: 8192, servers: null },
        }),
        node('pve3', { maintenance: true }),
        node('ams1', { location: 'ams' }),
    ];
    const groups = [
        group(),
        { value: 'hdd', label: 'HDD', location: 'ist', storages: [] },
    ];

    it('considers every node of the location for a package without groups', () => {
        expect(automaticFit('ist', [], groups, nodes, size)).toEqual({
            total: 2,
            fitting: 1,
        });
    });

    it('stays inside the package groups of the location', () => {
        expect(automaticFit('ist', ['hdd'], groups, nodes, size)).toEqual({
            total: 1,
            fitting: 0,
        });
        expect(automaticFit('ams', ['nvme'], groups, nodes, size)).toEqual({
            total: 0,
            fitting: 0,
        });
    });

    it('makes a test server of an inactive OS template', () => {
        const templates = [
            { value: 'debian', active: true },
            { value: 'pardus', active: false },
        ];

        expect(makesTestServer(templates, 'pardus')).toBe(true);
        expect(makesTestServer(templates, 'debian')).toBe(false);
        expect(makesTestServer(templates, null)).toBe(false);
    });
});
