import type { AppOsVersion } from '@/components/admin/AppSystemsPicker.vue';

/**
 * Filters of the Apps page: by platform and by the operating system versions
 * an app is ticked for (docs/adr/0026).
 */
export type PlatformFilter = 'all' | 'linux' | 'windows';

/** 'all', 'none' (ticked for no version), `system:<name>` or `version:<id>`. */
export type OsFilter = string;

type FilterableApp = { platform: string; os_version_ids: number[] };

export const matchesFilters = (
    app: FilterableApp,
    platform: PlatformFilter,
    os: OsFilter,
    versions: AppOsVersion[],
): boolean => {
    if (platform !== 'all' && app.platform !== platform) {
        return false;
    }

    if (os === 'all') {
        return true;
    }

    if (os === 'none') {
        return app.os_version_ids.length === 0;
    }

    if (os.startsWith('version:')) {
        return app.os_version_ids.includes(Number(os.slice(8)));
    }

    const system = os.slice(7);

    return versions.some(
        (version) =>
            version.system === system &&
            app.os_version_ids.includes(version.id),
    );
};

/**
 * The operating system choices for a platform: each system with "all
 * versions" first, then its versions, grouped by system.
 */
export const osFilterOptions = (
    versions: AppOsVersion[],
    platform: PlatformFilter,
    allVersionsLabel: string,
): { value: OsFilter; label: string; group: string }[] => {
    const options: { value: OsFilter; label: string; group: string }[] = [];

    for (const version of versions) {
        if (platform !== 'all' && version.platform !== platform) {
            continue;
        }

        if (!options.some((option) => option.group === version.system)) {
            options.push({
                value: `system:${version.system}`,
                label: allVersionsLabel,
                group: version.system,
            });
        }

        options.push({
            value: `version:${version.id}`,
            label: `${version.system} ${version.version}`,
            group: version.system,
        });
    }

    return options;
};
