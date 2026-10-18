import { describe, expect, it } from 'vite-plus/test';
import {
    allowsNewer,
    compareVersions,
    selected,
    setNewer,
    setRange,
    supports,
    toggle,
} from '@/lib/osPicker';
import type { Requires, TemplateVersion } from '@/lib/osPicker';

const groups = {
    debian: { families: ['debian', 'ubuntu'] },
    rhel: { families: ['almalinux', 'rocky', 'centos', 'fedora'] },
};

const templates: TemplateVersion[] = [
    { family: 'debian', version: '12' },
    { family: 'debian', version: '13' },
    { family: 'ubuntu', version: '22.04' },
    { family: 'ubuntu', version: '24.04' },
    { family: 'almalinux', version: '9' },
];

const linux = (extra: Partial<Requires> = {}): Requires => ({
    platform: 'linux',
    ...extra,
});

describe('osPicker', () => {
    it('compares dotted versions like version_compare', () => {
        expect(compareVersions('12', '13')).toBe(-1);
        expect(compareVersions('24.04', '22.04')).toBe(1);
        expect(compareVersions('12.0', '12')).toBe(0);
    });

    it('reads groups, lowest and highest versions like Recipe::supports()', () => {
        const requires = linux({
            os: ['debian'],
            min_versions: { ubuntu: '24.04', debian: '12' },
            max_versions: { debian: '12' },
        });

        expect(selected(requires, 'debian', templates, groups)).toEqual(['12']);
        expect(selected(requires, 'ubuntu', templates, groups)).toEqual([
            '24.04',
        ]);
        expect(selected(requires, 'almalinux', templates, groups)).toEqual([]);
        expect(supports(linux(), 'almalinux', '9', groups)).toBe(true);
        expect(supports(requires, 'debian', '12.7', groups)).toBe(true);
    });

    it('ticks a first version with newer ones and writes plain families', () => {
        const requires = toggle(
            linux({ os: ['ubuntu'] }),
            'almalinux',
            '9',
            templates,
            groups,
        );

        expect(requires.os).toEqual(['almalinux', 'ubuntu']);
        expect(requires.min_versions).toEqual({ almalinux: '9' });
        expect(allowsNewer(requires, 'almalinux')).toBe(true);
    });

    it('keeps Ubuntu out when only Debian is ticked', () => {
        const requires = toggle(
            linux({ os: ['almalinux'] }),
            'debian',
            '12',
            templates,
            groups,
        );

        expect(requires.os).toEqual(['almalinux', 'debian']);
        expect(requires.max_versions).toEqual({ ubuntu: '0' });
        expect(selected(requires, 'ubuntu', templates, groups)).toEqual([]);
        expect(selected(requires, 'debian', templates, groups)).toEqual([
            '12',
            '13',
        ]);

        const withUbuntu = toggle(
            requires,
            'ubuntu',
            '24.04',
            templates,
            groups,
        );

        expect(withUbuntu.os).toEqual(['almalinux', 'debian', 'ubuntu']);
        expect(withUbuntu.max_versions).toEqual({});
        expect(withUbuntu.min_versions).toEqual({
            debian: '12',
            ubuntu: '24.04',
        });
    });

    it('narrows and widens the range', () => {
        let requires = linux({
            os: ['debian'],
            min_versions: { debian: '12', ubuntu: '22.04' },
        });

        // Unticking 13 (not the lowest) ends the range below it.
        requires = toggle(requires, 'debian', '13', templates, groups);
        expect(requires.max_versions).toEqual({ debian: '12' });
        expect(selected(requires, 'debian', templates, groups)).toEqual(['12']);

        // Ticking 13 again widens to it; newer stays off.
        requires = toggle(requires, 'debian', '13', templates, groups);
        expect(requires.max_versions).toEqual({ debian: '13' });

        requires = setNewer(requires, 'debian', true, templates, groups);
        expect(allowsNewer(requires, 'debian')).toBe(true);

        // Unticking the lowest raises the range; the last one removes the family.
        requires = toggle(requires, 'ubuntu', '22.04', templates, groups);
        expect(requires.min_versions?.ubuntu).toBe('24.04');
        requires = toggle(requires, 'ubuntu', '24.04', templates, groups);
        expect(requires.os).toEqual(['debian']);
        expect(requires.max_versions).toEqual({ ubuntu: '0' });
    });

    it('turns any OS into the families that have templates', () => {
        const requires = toggle(linux(), 'almalinux', '9', templates, groups);

        expect(requires.os).toEqual(['debian', 'ubuntu']);
        expect(selected(requires, 'debian', templates, groups)).toEqual([
            '12',
            '13',
        ]);
    });

    it('keeps families without a template in this panel', () => {
        const requires = setRange(
            linux({ os: ['rocky', 'debian'], min_versions: { rocky: '9' } }),
            'debian',
            '13',
            null,
            templates,
            groups,
        );

        expect(requires.os).toEqual(['debian', 'rocky', 'ubuntu']);
        expect(requires.min_versions).toEqual({ rocky: '9', debian: '13' });
    });
});
