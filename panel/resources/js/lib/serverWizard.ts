import type { PlacementGroupOption, PlacementNode } from '@/types/admin';

/**
 * The steps of the admin "Create server" wizard and the form fields each one
 * owns, so a server-side error can send the administrator back to its step.
 * Titles are English keys, translated where they are shown.
 */
export const WIZARD_STEPS = [
    {
        key: 'type',
        title: 'Type & location',
        fields: ['resource_mode', 'location'],
    },
    {
        // A package (placed automatically) or custom resources and their placement.
        key: 'resources',
        title: 'Resources & placement',
        fields: [
            'package',
            'cpu_cores',
            'memory_mib',
            'disk_gib',
            'ipv4_count',
            'ipv6_count',
            'port_speed_mbps',
            'traffic_gb',
            'cpu_limit',
            'cpu_units',
            'disk_mb_s',
            'disk_iops',
            'snapshot_slots',
            'backup_slots',
            'placement',
            'placement_group',
            'node',
            'storage',
        ],
    },
    {
        key: 'system',
        title: 'Operating system & app',
        fields: ['os_template', 'app', 'app_inputs'],
    },
    {
        key: 'network',
        title: 'Network & IPs',
        fields: ['network_model', 'ipv4_pool', 'ipv4_addresses'],
    },
    {
        key: 'access',
        title: 'Access & billing',
        fields: [
            'hostname',
            'root_password',
            'ssh_keys',
            'billing_system',
            'external_id',
            'external_client_id',
        ],
    },
    // General errors (no capacity, Proxmox unreachable…) come back as "server".
    { key: 'review', title: 'Review', fields: ['server'] },
] as const;

/**
 * The first step with an error, or null. Keys like "app_inputs.port" or
 * "ipv4_addresses.0" belong to the step of the part before the dot.
 */
export function firstStepWithError(
    errors: Record<string, unknown>,
): number | null {
    const fields = Object.keys(errors).map((key) => key.split('.')[0]);
    const index = WIZARD_STEPS.findIndex((step) =>
        fields.some((field) =>
            (step.fields as readonly string[]).includes(field),
        ),
    );

    return index === -1 ? null : index;
}

export type ServerSize = {
    cpu_cores: number;
    memory_mib: number;
    disk_mib: number;
};

export type FitProblem = 'offline' | 'cpu' | 'memory' | 'servers' | 'disk';

/**
 * What keeps a server of this size off the node, as placement counts it
 * (empty: it fits). $storages are the storages the disk may go to.
 */
export function nodeProblems(
    node: PlacementNode,
    size: ServerSize,
    storages: PlacementNode['storages'],
): FitProblem[] {
    const problems: FitProblem[] = [];
    const { cpu_cores: cpu, memory_mib: memory, servers } = node.capacity;

    if (!node.online) {
        problems.push('offline');
    }

    if (cpu !== null && cpu < size.cpu_cores) {
        problems.push('cpu');
    }

    if (memory !== null && memory < size.memory_mib) {
        problems.push('memory');
    }

    if (servers !== null && servers < 1) {
        problems.push('servers');
    }

    if (!storages.some((storage) => storage.free_mib >= size.disk_mib)) {
        problems.push('disk');
    }

    return problems;
}

/**
 * The storages of a chosen node the disk may go to: the chosen one, or
 * (automatic) those marked "use for servers".
 */
export function nodeStorages(
    node: PlacementNode,
    storage: string,
): PlacementNode['storages'] {
    return storage === ''
        ? node.storages.filter((candidate) => candidate.use_for_servers)
        : node.storages.filter((candidate) => candidate.name === storage);
}

/**
 * The storages of a group's node the disk may go to: those the group lists
 * on that node, or (none listed) those marked "use for servers".
 */
export function groupStorages(
    group: PlacementGroupOption,
    node: PlacementNode,
): PlacementNode['storages'] {
    return group.storages.length === 0
        ? node.storages.filter((candidate) => candidate.use_for_servers)
        : node.storages.filter((candidate) =>
              group.storages.includes(`${node.value}/${candidate.name}`),
          );
}

/**
 * Automatic placement of a package server, as the billing API does it: the
 * nodes of the location (not in maintenance), within the package's placement
 * groups when it names any, and how many of them a server of this size fits on.
 */
export function automaticFit(
    location: string | null,
    packageGroups: string[],
    groups: PlacementGroupOption[],
    nodes: PlacementNode[],
    size: ServerSize,
): { total: number; fitting: number } {
    const usable = groups.filter(
        (group) =>
            group.location === location && packageGroups.includes(group.value),
    );
    const members = nodes.filter(
        (node) =>
            node.location === location &&
            !node.maintenance &&
            (packageGroups.length === 0 ||
                usable.some((group) =>
                    node.placement_groups.includes(group.value),
                )),
    );
    const storagesOf = (node: PlacementNode) =>
        packageGroups.length === 0
            ? node.storages.filter((storage) => storage.use_for_servers)
            : usable
                  .filter((group) =>
                      node.placement_groups.includes(group.value),
                  )
                  .flatMap((group) => groupStorages(group, node));

    return {
        total: members.length,
        fitting: members.filter(
            (node) => nodeProblems(node, size, storagesOf(node)).length === 0,
        ).length,
    };
}

/**
 * The nodes of a group placement would consider (not in maintenance) and how
 * many of them a server of this size fits on.
 */
export function groupFit(
    group: PlacementGroupOption,
    nodes: PlacementNode[],
    size: ServerSize,
): { total: number; fitting: number } {
    const members = nodes.filter(
        (node) =>
            node.placement_groups.includes(group.value) && !node.maintenance,
    );

    return {
        total: members.length,
        fitting: members.filter(
            (node) =>
                nodeProblems(node, size, groupStorages(group, node)).length ===
                0,
        ).length,
    };
}

/**
 * Whether the chosen OS template is inactive: the server is then a test
 * server without a billing system (docs/adr/0024 §8).
 */
export function makesTestServer(
    osTemplates: { value: string; active: boolean }[],
    chosen: string | null,
): boolean {
    return (
        osTemplates.find((option) => option.value === chosen)?.active === false
    );
}

/**
 * The OS templates a server of the chosen package may use: the ones the
 * package names, or all of them when it names none or there is no package
 * (a custom server) (docs/adr/0040).
 */
export function templatesFor<T extends { value: string }>(
    osTemplates: T[],
    pkg: { os_templates: string[] } | undefined,
): T[] {
    if (pkg === undefined || pkg.os_templates.length === 0) {
        return osTemplates;
    }

    return osTemplates.filter((option) =>
        pkg.os_templates.includes(option.value),
    );
}

/**
 * The first package that may use the OS template: one that names it or names
 * none (docs/adr/0040). Undefined when no package allows it.
 */
export function packageAllowing(
    packages: { value: string; os_templates: string[] }[],
    template: string,
): string | undefined {
    return packages.find(
        (pkg) =>
            pkg.os_templates.length === 0 ||
            pkg.os_templates.includes(template),
    )?.value;
}
