import type { RecipeDefinition } from '@/lib/recipes';

/**
 * The OS picker of the app editor's requirements (docs/adr/0018 §5): the
 * administrator ticks the versions of their own OS templates and the recipe
 * gets plain family names with the lowest and highest version
 * (docs/adr/0016 §3). What is ticked is read back with the same rules as
 * Recipe::supports(), so it is exactly what the panel offers the app on.
 *
 * Recipes write groups like /etc/os-release ID_LIKE, and "debian" is both a
 * family and the group of Debian and Ubuntu. Debian without Ubuntu is
 * therefore written as `os: [debian]` with `max_versions: { ubuntu: '0' }`,
 * which no Ubuntu template meets.
 */

export type Requires = RecipeDefinition['requires'];

/** One family and version of the panel's active Linux OS templates. */
export type TemplateVersion = { family: string; version: string };

export type OsGroups = Record<string, { families: string[] }>;

/** The family that is also the name of a group covering another family. */
const SHARED_NAME = 'debian';
const SHARED_WITH = 'ubuntu';
/** A highest version no template has: keeps a family out of its group. */
const EXCLUDED = '0';

function numbers(version: string): number[] {
    return version.split('.').map((part) => Number.parseInt(part, 10) || 0);
}

/** Like PHP's version_compare for dotted numbers: -1, 0 or 1. */
export function compareVersions(a: string, b: string): number {
    const left = numbers(a);
    const right = numbers(b);

    for (let i = 0; i < Math.max(left.length, right.length); i++) {
        const difference = (left[i] ?? 0) - (right[i] ?? 0);

        if (difference !== 0) {
            return difference < 0 ? -1 : 1;
        }
    }

    return 0;
}

/** The version cut to as many parts as the limit has (a highest "12" covers "12.7"). */
function cut(version: string, limit: string): string {
    return version.split('.').slice(0, limit.split('.').length).join('.');
}

function covers(os: string[], family: string, groups: OsGroups): boolean {
    return (
        os.length === 0 ||
        os.some(
            (name) =>
                name === family ||
                (groups[name]?.families ?? []).includes(family),
        )
    );
}

/** Whether the recipe runs on this template family and version (Recipe::supports()). */
export function supports(
    requires: Requires,
    family: string,
    version: string,
    groups: OsGroups,
): boolean {
    if (!covers(requires.os ?? [], family, groups)) {
        return false;
    }

    const minimum = requires.min_versions?.[family];
    const maximum = requires.max_versions?.[family];

    return (
        (minimum === undefined ||
            minimum === '' ||
            compareVersions(version, minimum) >= 0) &&
        (maximum === undefined ||
            maximum === '' ||
            compareVersions(cut(version, maximum), maximum) <= 0)
    );
}

/** The template versions by family, lowest first. */
export function familiesOf(
    templates: TemplateVersion[],
): Record<string, string[]> {
    const families: Record<string, string[]> = {};

    for (const { family, version } of templates) {
        (families[family] ??= []).push(version);
    }

    for (const versions of Object.values(families)) {
        versions.sort(compareVersions);
    }

    return families;
}

/** The ticked versions of a family, lowest first. */
export function selected(
    requires: Requires,
    family: string,
    templates: TemplateVersion[],
    groups: OsGroups,
): string[] {
    return (familiesOf(templates)[family] ?? []).filter((version) =>
        supports(requires, family, version, groups),
    );
}

/** The families the recipe names, groups written out (none: any OS). */
export function expandedFamilies(
    requires: Requires,
    groups: OsGroups,
): string[] {
    return [
        ...new Set(
            (requires.os ?? []).flatMap(
                (name) => groups[name]?.families ?? [name],
            ),
        ),
    ];
}

/** Whether newer versions of the family (templates added later) are allowed too. */
export function allowsNewer(requires: Requires, family: string): boolean {
    const maximum = requires.max_versions?.[family];

    return maximum === undefined || maximum === '';
}

/**
 * The requirements with one family's range replaced: from `min` to `max`
 * (null: no highest version), or without the family when `min` is null.
 * Groups are written out as families; families without a template in this
 * panel keep their range.
 */
export function setRange(
    requires: Requires,
    family: string,
    min: string | null,
    max: string | null,
    templates: TemplateVersion[],
    groups: OsGroups,
): Requires {
    const os = requires.os ?? [];
    const known = Object.keys(familiesOf(templates));
    // Any OS (no list) becomes every family the panel has templates of.
    const families = new Set(
        os.length === 0
            ? known
            : os.flatMap((name) => groups[name]?.families ?? [name]),
    );
    const minVersions = { ...requires.min_versions };
    const maxVersions = { ...requires.max_versions };

    // The marker that kept Ubuntu out is rebuilt below when still needed.
    if (maxVersions[SHARED_WITH] === EXCLUDED) {
        delete maxVersions[SHARED_WITH];
        families.delete(SHARED_WITH);
    }

    if (min === null) {
        families.delete(family);
        delete minVersions[family];
        delete maxVersions[family];
    } else {
        families.add(family);
        minVersions[family] = min;

        if (max === null) {
            delete maxVersions[family];
        } else {
            maxVersions[family] = max;
        }
    }

    for (const name of Object.keys(minVersions)) {
        if (!families.has(name)) {
            delete minVersions[name];
        }
    }

    for (const name of Object.keys(maxVersions)) {
        if (!families.has(name)) {
            delete maxVersions[name];
        }
    }

    if (families.has(SHARED_NAME) && !families.has(SHARED_WITH)) {
        maxVersions[SHARED_WITH] = EXCLUDED;
    }

    return {
        ...requires,
        os: [...families].sort(),
        min_versions: minVersions,
        max_versions: maxVersions,
    };
}

/**
 * Tick or untick one version. A version outside the range widens it (a
 * family's first version allows newer ones too); the lowest ticked version
 * raises the range; any other ticked version ends the range below it.
 */
export function toggle(
    requires: Requires,
    family: string,
    version: string,
    templates: TemplateVersion[],
    groups: OsGroups,
): Requires {
    const ticked = selected(requires, family, templates, groups);
    const open = ticked.length === 0 || allowsNewer(requires, family);

    if (!ticked.includes(version)) {
        const range = [...ticked, version].sort(compareVersions);

        return setRange(
            requires,
            family,
            range[0],
            open ? null : range[range.length - 1],
            templates,
            groups,
        );
    }

    if (version === ticked[0]) {
        const rest = ticked.slice(1);

        return rest.length === 0
            ? setRange(requires, family, null, null, templates, groups)
            : setRange(
                  requires,
                  family,
                  rest[0],
                  open ? null : rest[rest.length - 1],
                  templates,
                  groups,
              );
    }

    return setRange(
        requires,
        family,
        ticked[0],
        ticked[ticked.indexOf(version) - 1],
        templates,
        groups,
    );
}

/** Allow newer versions of a family (no highest version), or stop at the highest ticked one. */
export function setNewer(
    requires: Requires,
    family: string,
    newer: boolean,
    templates: TemplateVersion[],
    groups: OsGroups,
): Requires {
    const ticked = selected(requires, family, templates, groups);

    if (ticked.length === 0) {
        return requires;
    }

    return setRange(
        requires,
        family,
        ticked[0],
        newer ? null : ticked[ticked.length - 1],
        templates,
        groups,
    );
}
