import { describe, expect, it } from 'vite-plus/test';
import {
    OTHER,
    compatibleOptions,
    distributionOptions,
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
        example: '13',
    },
    {
        id: 'ubuntu',
        name: 'Ubuntu',
        family: 'debian',
        base: null,
        same_version: false,
        example: '24.04',
    },
    {
        id: 'pardus',
        name: 'Pardus',
        family: 'debian',
        base: 'debian',
        same_version: false,
        example: '23.4',
    },
    {
        id: 'almalinux',
        name: 'AlmaLinux',
        family: 'rhel',
        base: null,
        same_version: false,
        example: '9.6',
    },
    {
        id: 'windows',
        name: 'Windows',
        family: 'windows',
        base: null,
        same_version: false,
        example: '2022',
    },
];

const families: Family[] = [
    { value: 'debian', label: 'Debian family', custom: true },
    { value: 'rhel', label: 'RHEL family', custom: true },
    { value: 'windows', label: 'Windows', custom: false },
];

describe('osTemplates', () => {
    it('groups the distributions by family and ends with Other', () => {
        const options = distributionOptions(
            distributions,
            families,
            (key) => key,
        );

        expect(options[0]).toEqual({
            value: 'debian',
            label: 'Debian',
            group: 'Debian family',
        });
        expect(options[3].group).toBe('RHEL family');
        expect(options[options.length - 1]).toEqual({
            value: OTHER,
            label: 'Other…',
        });
    });

    it('offers only base systems of the same family as compatible', () => {
        expect(
            compatibleOptions(distributions, 'debian').map((entry) => entry.id),
        ).toEqual(['debian', 'ubuntu']);
        expect(compatibleOptions(distributions, 'windows')).toEqual([]);
        expect(compatibleOptions(distributions, 'other')).toEqual([]);
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
