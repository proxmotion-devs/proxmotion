import { describe, expect, it } from 'vite-plus/test';
import { findVersion, suggestedName, versionOptions } from '@/lib/osTemplates';
import type { OperatingSystem } from '@/lib/osTemplates';

const systems: OperatingSystem[] = [
    {
        id: 1,
        name: 'Ubuntu',
        platform: 'linux',
        versions: [
            { id: 10, version: '26.04', templates: 0 },
            { id: 11, version: '24.04', templates: 2 },
        ],
    },
    {
        id: 2,
        name: 'Windows Server',
        platform: 'windows',
        versions: [{ id: 20, version: '2022', templates: 0 }],
    },
];

describe('osTemplates', () => {
    it('lists every version grouped by its system', () => {
        expect(versionOptions(systems)).toEqual([
            { value: 10, label: 'Ubuntu 26.04', group: 'Ubuntu' },
            { value: 11, label: 'Ubuntu 24.04', group: 'Ubuntu' },
            {
                value: 20,
                label: 'Windows Server 2022',
                group: 'Windows Server',
            },
        ]);
    });

    it('finds the system of a version', () => {
        expect(findVersion(systems, 20)?.system.name).toBe('Windows Server');
        expect(findVersion(systems, 99)).toBeUndefined();
        expect(findVersion(systems, null)).toBeUndefined();
    });

    it('suggests a name from the system and version', () => {
        expect(suggestedName(systems[0], '24.04')).toBe('Ubuntu 24.04 LTS');
        expect(suggestedName(systems[0], '25.10')).toBe('Ubuntu 25.10');
        expect(suggestedName(systems[1], '2022')).toBe('Windows Server 2022');
        expect(suggestedName(undefined, '1')).toBe('');
    });
});
