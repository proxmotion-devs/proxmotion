import { describe, expect, it } from 'vite-plus/test';
import type { AppOsVersion } from '@/components/admin/AppSystemsPicker.vue';
import { matchesFilters, osFilterOptions } from '@/lib/appFilters';

const versions: AppOsVersion[] = [
    { id: 1, system: 'Ubuntu', version: '24.04', platform: 'linux' },
    { id: 2, system: 'Ubuntu', version: '22.04', platform: 'linux' },
    { id: 3, system: 'Debian', version: '12', platform: 'linux' },
    { id: 4, system: 'Windows Server', version: '2022', platform: 'windows' },
];

const docker = { platform: 'linux', os_version_ids: [1, 3] };
const iis = { platform: 'windows', os_version_ids: [4] };
const draft = { platform: 'linux', os_version_ids: [] };

describe('matchesFilters', () => {
    it('keeps every app without filters', () => {
        for (const app of [docker, iis, draft]) {
            expect(matchesFilters(app, 'all', 'all', versions)).toBe(true);
        }
    });

    it('filters by platform', () => {
        expect(matchesFilters(docker, 'linux', 'all', versions)).toBe(true);
        expect(matchesFilters(iis, 'linux', 'all', versions)).toBe(false);
        expect(matchesFilters(iis, 'windows', 'all', versions)).toBe(true);
    });

    it('filters by system, by version and by no system', () => {
        expect(matchesFilters(docker, 'all', 'system:Ubuntu', versions)).toBe(
            true,
        );
        expect(matchesFilters(iis, 'all', 'system:Ubuntu', versions)).toBe(
            false,
        );
        expect(matchesFilters(docker, 'all', 'version:2', versions)).toBe(
            false,
        );
        expect(matchesFilters(docker, 'all', 'version:3', versions)).toBe(true);
        expect(matchesFilters(draft, 'all', 'none', versions)).toBe(true);
        expect(matchesFilters(docker, 'all', 'none', versions)).toBe(false);
    });
});

describe('osFilterOptions', () => {
    it('lists each system with all its versions first', () => {
        expect(
            osFilterOptions(versions, 'all', 'All versions').map(
                (option) => option.value,
            ),
        ).toEqual([
            'system:Ubuntu',
            'version:1',
            'version:2',
            'system:Debian',
            'version:3',
            'system:Windows Server',
            'version:4',
        ]);
    });

    it('lists only the systems of the chosen platform', () => {
        expect(osFilterOptions(versions, 'windows', 'All versions')).toEqual([
            {
                value: 'system:Windows Server',
                label: 'All versions',
                group: 'Windows Server',
            },
            {
                value: 'version:4',
                label: 'Windows Server 2022',
                group: 'Windows Server',
            },
        ]);
    });
});
