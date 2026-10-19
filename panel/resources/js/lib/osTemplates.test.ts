import { describe, expect, it } from 'vite-plus/test';
import {
    OTHER,
    releaseOf,
    releaseOptions,
    releaseValue,
    suggestedName,
    systemParts,
} from '@/lib/osTemplates';
import type { Distribution, Family } from '@/lib/osTemplates';

const distributions: Distribution[] = [
    {
        id: 'debian',
        name: 'Debian',
        family: 'debian',
        base: null,
        same_version: false,
        releases: [
            { id: 1, version: '13', base_version: null },
            { id: 2, version: '12', base_version: null },
        ],
    },
    {
        id: 'ubuntu',
        name: 'Ubuntu',
        family: 'debian',
        base: null,
        same_version: false,
        releases: [{ id: 3, version: '24.04', base_version: null }],
    },
    {
        id: 'pardus',
        name: 'Pardus',
        family: 'debian',
        base: 'debian',
        same_version: false,
        releases: [{ id: 4, version: '23', base_version: '12' }],
    },
    {
        id: 'almalinux',
        name: 'AlmaLinux',
        family: 'rhel',
        base: null,
        same_version: false,
        releases: [{ id: 5, version: '9', base_version: null }],
    },
    {
        id: 'windows',
        name: 'Windows',
        family: 'windows',
        base: null,
        same_version: false,
        releases: [{ id: 6, version: '2022', base_version: null }],
    },
];

const families: Family[] = [
    { value: 'debian', label: 'Debian family', custom: true },
    { value: 'rhel', label: 'RHEL family', custom: true },
    { value: 'windows', label: 'Windows', custom: false },
];

describe('osTemplates', () => {
    it('lists the releases grouped by family and ends with Other', () => {
        const options = releaseOptions(distributions, families, (key) => key, {
            distribution: 'ubuntu',
            version: '25.10',
        });

        expect(options[0]).toEqual({
            value: 'debian@13',
            label: 'Debian 13',
            group: 'Debian family',
        });
        // A version no listed release covers stays selectable.
        expect(options.map((option) => option.value)).toContain('ubuntu@25.10');
        expect(options.find((o) => o.value === 'windows@2022')?.label).toBe(
            'Windows Server 2022',
        );
        expect(options[options.length - 1]).toEqual({
            value: OTHER,
            label: 'Other…',
        });
    });

    it('finds the release a point release belongs to', () => {
        expect(releaseOf(distributions[3], '9.6')).toBe('9');
        expect(releaseOf(distributions[2], '23.4')).toBe('23');
        expect(releaseOf(distributions[0], '11')).toBeUndefined();
        expect(releaseValue('pardus', '23')).toBe('pardus@23');
    });

    it('suggests a name from the system and version', () => {
        expect(suggestedName(distributions[1], 'ubuntu', '24.04')).toBe(
            'Ubuntu 24.04 LTS',
        );
        expect(suggestedName(distributions[1], 'ubuntu', '25.10')).toBe(
            'Ubuntu 25.10',
        );
        expect(suggestedName(distributions[4], 'windows', '2022')).toBe(
            'Windows Server 2022',
        );
        expect(suggestedName(undefined, 'zorin', '17')).toBe('zorin 17');
    });

    it('describes a template matched as another system', () => {
        expect(
            systemParts(
                {
                    distribution: 'pardus',
                    version: '23.4',
                    family: 'debian',
                    compatible_with: 'debian',
                    compatible_version: '12',
                },
                distributions,
            ),
        ).toEqual({
            system: 'Pardus 23.4',
            family: 'debian',
            matchedAs: 'Debian 12',
        });
    });
});
