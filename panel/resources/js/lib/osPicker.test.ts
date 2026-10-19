import { describe, expect, it } from 'vite-plus/test';
import {
    allowsNewer,
    compareVersions,
    coveredDistributions,
    selected,
    setNewer,
    setRange,
    supports,
    toggle,
} from '@/lib/osPicker';
import type { Requires, TemplateVersion } from '@/lib/osPicker';
import type { Distribution } from '@/lib/osTemplates';

const entry = (
    id: string,
    family: string,
    base: string | null = null,
): Distribution => ({
    id,
    name: id,
    family,
    base,
    same_version: false,
    example: '1',
});

const catalog: Distribution[] = [
    entry('debian', 'debian'),
    entry('ubuntu', 'debian'),
    entry('pardus', 'debian', 'debian'),
    entry('almalinux', 'rhel'),
    entry('rocky', 'rhel'),
];

const templates: TemplateVersion[] = [
    { distribution: 'debian', version: '12' },
    { distribution: 'debian', version: '13' },
    { distribution: 'ubuntu', version: '22.04' },
    { distribution: 'ubuntu', version: '24.04' },
    { distribution: 'almalinux', version: '9' },
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

    it('reads families, lowest and highest versions like Recipe::supports()', () => {
        const requires = linux({
            os: { families: ['debian'] },
            min_versions: { ubuntu: '24.04', debian: '12' },
            max_versions: { debian: '12' },
        });

        expect(coveredDistributions(requires, catalog)).toEqual([
            'debian',
            'ubuntu',
        ]);
        expect(selected(requires, 'debian', templates, catalog)).toEqual([
            '12',
        ]);
        expect(selected(requires, 'ubuntu', templates, catalog)).toEqual([
            '24.04',
        ]);
        expect(selected(requires, 'almalinux', templates, catalog)).toEqual([]);
        expect(supports(linux(), 'almalinux', '9', catalog)).toBe(true);
        expect(supports(requires, 'debian', '12.7', catalog)).toBe(true);
    });

    it('ticks a first version with newer ones and writes distributions', () => {
        const requires = toggle(
            linux({ os: { distributions: ['ubuntu'] } }),
            'almalinux',
            '9',
            templates,
            catalog,
        );

        expect(requires.os).toEqual({ distributions: ['almalinux', 'ubuntu'] });
        expect(requires.min_versions).toEqual({ almalinux: '9' });
        expect(allowsNewer(requires, 'almalinux')).toBe(true);
    });

    it('ticks Debian without Ubuntu', () => {
        const requires = toggle(
            linux({ os: { distributions: ['almalinux'] } }),
            'debian',
            '12',
            templates,
            catalog,
        );

        expect(requires.os).toEqual({
            distributions: ['almalinux', 'debian'],
        });
        expect(requires.max_versions).toEqual({});
        expect(selected(requires, 'ubuntu', templates, catalog)).toEqual([]);
        expect(selected(requires, 'debian', templates, catalog)).toEqual([
            '12',
            '13',
        ]);
    });

    it('writes a family out as its distributions when one is changed', () => {
        const requires = toggle(
            linux({
                os: { families: ['debian'] },
                min_versions: { debian: '12', ubuntu: '22.04' },
            }),
            'ubuntu',
            '22.04',
            templates,
            catalog,
        );

        expect(requires.os).toEqual({ distributions: ['debian', 'ubuntu'] });
        expect(requires.min_versions).toEqual({
            debian: '12',
            ubuntu: '24.04',
        });
    });

    it('narrows and widens the range', () => {
        let requires = linux({
            os: { distributions: ['debian', 'ubuntu'] },
            min_versions: { debian: '12', ubuntu: '22.04' },
        });

        // Unticking 13 (not the lowest) ends the range below it.
        requires = toggle(requires, 'debian', '13', templates, catalog);
        expect(requires.max_versions).toEqual({ debian: '12' });
        expect(selected(requires, 'debian', templates, catalog)).toEqual([
            '12',
        ]);

        // Ticking 13 again widens to it; newer stays off.
        requires = toggle(requires, 'debian', '13', templates, catalog);
        expect(requires.max_versions).toEqual({ debian: '13' });

        requires = setNewer(requires, 'debian', true, templates, catalog);
        expect(allowsNewer(requires, 'debian')).toBe(true);

        // Unticking the lowest raises the range; the last one removes the distribution.
        requires = toggle(requires, 'ubuntu', '22.04', templates, catalog);
        expect(requires.min_versions?.ubuntu).toBe('24.04');
        requires = toggle(requires, 'ubuntu', '24.04', templates, catalog);
        expect(requires.os).toEqual({ distributions: ['debian'] });
        expect(requires.max_versions).toEqual({});
    });

    it('turns any OS into the distributions that have templates', () => {
        const requires = toggle(linux(), 'almalinux', '9', templates, catalog);

        expect(requires.os).toEqual({ distributions: ['debian', 'ubuntu'] });
        expect(selected(requires, 'debian', templates, catalog)).toEqual([
            '12',
            '13',
        ]);
    });

    it('keeps distributions without a template in this panel', () => {
        const requires = setRange(
            linux({
                os: { families: ['rhel'], distributions: ['debian'] },
                min_versions: { rocky: '9' },
            }),
            'debian',
            '13',
            null,
            templates,
            catalog,
        );

        expect(requires.os).toEqual({
            distributions: ['almalinux', 'debian', 'rocky'],
        });
        expect(requires.min_versions).toEqual({ rocky: '9', debian: '13' });
    });
});
