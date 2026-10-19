import type { Distribution } from '@/lib/osTemplates';
import type { RecipeDefinition } from '@/lib/recipes';

/**
 * The OS picker of the app editor's requirements (docs/adr/0018 §5): the
 * administrator ticks the versions of their own OS templates and the recipe
 * gets the distributions with their lowest and highest version
 * (docs/adr/0024 §4). What is ticked is read back with the same rules as
 * Recipe::supports(), so it is exactly what the panel offers the app on.
 *
 * A recipe may name whole families (`os.families`). Families only cover the
 * catalog's base distributions (a derivative is matched as its base), so a
 * change writes them out as those distributions without changing what fits.
 */

export type Requires = RecipeDefinition['requires'];

/** One distribution and version the panel's active Linux OS templates are matched as. */
export type TemplateVersion = { distribution: string; version: string };

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

/** Whether the recipe names no operating system (any of the platform). */
export function anyOs(requires: Requires): boolean {
    return (
        (requires.os?.families ?? []).length === 0 &&
        (requires.os?.distributions ?? []).length === 0
    );
}

/** The distributions `os` covers by name: its families written out, then its own. */
export function coveredDistributions(
    requires: Requires,
    catalog: Distribution[],
): string[] {
    const families = requires.os?.families ?? [];

    return [
        ...new Set([
            ...catalog
                .filter(
                    (entry) =>
                        entry.base === null && families.includes(entry.family),
                )
                .map((entry) => entry.id),
            ...(requires.os?.distributions ?? []),
        ]),
    ];
}

/** Whether the recipe runs on this distribution and version (Recipe::supports()). */
export function supports(
    requires: Requires,
    distribution: string,
    version: string,
    catalog: Distribution[],
): boolean {
    if (
        !anyOs(requires) &&
        !coveredDistributions(requires, catalog).includes(distribution)
    ) {
        return false;
    }

    const minimum = requires.min_versions?.[distribution];
    const maximum = requires.max_versions?.[distribution];

    return (
        (minimum === undefined ||
            minimum === '' ||
            compareVersions(version, minimum) >= 0) &&
        (maximum === undefined ||
            maximum === '' ||
            compareVersions(cut(version, maximum), maximum) <= 0)
    );
}

/** The template versions by distribution, lowest first. */
export function distributionsOf(
    templates: TemplateVersion[],
): Record<string, string[]> {
    const distributions: Record<string, string[]> = {};

    for (const { distribution, version } of templates) {
        (distributions[distribution] ??= []).push(version);
    }

    for (const versions of Object.values(distributions)) {
        versions.sort(compareVersions);
    }

    return distributions;
}

/** The ticked versions of a distribution, lowest first. */
export function selected(
    requires: Requires,
    distribution: string,
    templates: TemplateVersion[],
    catalog: Distribution[],
): string[] {
    return (distributionsOf(templates)[distribution] ?? []).filter((version) =>
        supports(requires, distribution, version, catalog),
    );
}

/** Whether newer versions of the distribution (templates added later) are allowed too. */
export function allowsNewer(requires: Requires, distribution: string): boolean {
    const maximum = requires.max_versions?.[distribution];

    return maximum === undefined || maximum === '';
}

/**
 * The requirements with one distribution's range replaced: from `min` to
 * `max` (null: no highest version), or without the distribution when `min`
 * is null. Families are written out as distributions; distributions without
 * a template in this panel keep their range.
 */
export function setRange(
    requires: Requires,
    distribution: string,
    min: string | null,
    max: string | null,
    templates: TemplateVersion[],
    catalog: Distribution[],
): Requires {
    // Any OS (no list) becomes every distribution the panel has templates of.
    const distributions = new Set(
        anyOs(requires)
            ? Object.keys(distributionsOf(templates))
            : coveredDistributions(requires, catalog),
    );
    const minVersions = { ...requires.min_versions };
    const maxVersions = { ...requires.max_versions };

    if (min === null) {
        distributions.delete(distribution);
        delete minVersions[distribution];
        delete maxVersions[distribution];
    } else {
        distributions.add(distribution);
        minVersions[distribution] = min;

        if (max === null) {
            delete maxVersions[distribution];
        } else {
            maxVersions[distribution] = max;
        }
    }

    for (const limits of [minVersions, maxVersions]) {
        for (const name of Object.keys(limits)) {
            if (!distributions.has(name)) {
                delete limits[name];
            }
        }
    }

    const { os: _os, ...rest } = requires;

    return {
        ...rest,
        ...(distributions.size
            ? { os: { distributions: [...distributions].sort() } }
            : {}),
        min_versions: minVersions,
        max_versions: maxVersions,
    };
}

/**
 * Tick or untick one version. A version outside the range widens it (a
 * distribution's first version allows newer ones too); the lowest ticked
 * version raises the range; any other ticked version ends the range below it.
 */
export function toggle(
    requires: Requires,
    distribution: string,
    version: string,
    templates: TemplateVersion[],
    catalog: Distribution[],
): Requires {
    const ticked = selected(requires, distribution, templates, catalog);
    const open = ticked.length === 0 || allowsNewer(requires, distribution);

    if (!ticked.includes(version)) {
        const range = [...ticked, version].sort(compareVersions);

        return setRange(
            requires,
            distribution,
            range[0],
            open ? null : range[range.length - 1],
            templates,
            catalog,
        );
    }

    if (version === ticked[0]) {
        const rest = ticked.slice(1);

        return rest.length === 0
            ? setRange(requires, distribution, null, null, templates, catalog)
            : setRange(
                  requires,
                  distribution,
                  rest[0],
                  open ? null : rest[rest.length - 1],
                  templates,
                  catalog,
              );
    }

    return setRange(
        requires,
        distribution,
        ticked[0],
        ticked[ticked.indexOf(version) - 1],
        templates,
        catalog,
    );
}

/** Allow newer versions of a distribution (no highest version), or stop at the highest ticked one. */
export function setNewer(
    requires: Requires,
    distribution: string,
    newer: boolean,
    templates: TemplateVersion[],
    catalog: Distribution[],
): Requires {
    const ticked = selected(requires, distribution, templates, catalog);

    if (ticked.length === 0) {
        return requires;
    }

    return setRange(
        requires,
        distribution,
        ticked[0],
        newer ? null : ticked[ticked.length - 1],
        templates,
        catalog,
    );
}
