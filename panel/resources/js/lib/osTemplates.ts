/**
 * The OS template form's catalog of distributions (docs/adr/0024, 0025):
 * the systems and releases administrators manage and pick from; the family
 * and the system apps are matched as follow from it on the server.
 */

/** A distribution the panel knows (Distributions::forForm()). */
export type Distribution = {
    id: string;
    name: string;
    family: string;
    /** The distribution it is compatible with (pardus → debian). */
    base: string | null;
    /** Its versions are the base's (RHEL 9 = AlmaLinux 9). */
    same_version: boolean;
    /**
     * Releases the form lists, newest first ("rolling" for a rolling
     * distribution); those of a derivative name the release of its base.
     */
    releases: Release[];
};

export type Release = {
    id: number;
    version: string;
    base_version: string | null;
};

export type Family = { value: string; label: string; custom: boolean };

/** The select value for a distribution the panel does not know. */
export const OTHER = '__other';

export function find(
    distributions: Distribution[],
    id: string | null | undefined,
): Distribution | undefined {
    return distributions.find((distribution) => distribution.id === id);
}

/** A value of the form's one select: a release of a distribution ("ubuntu@24.04"). */
export function releaseValue(distribution: string, version: string): string {
    return `${distribution}@${version}`;
}

/**
 * The listed release a version belongs to: "23" for Pardus 23.4, "rolling"
 * for any version of a rolling distribution; undefined when none covers it.
 */
export function releaseOf(
    distribution: Distribution | undefined,
    version: string,
): string | undefined {
    return distribution?.releases
        .map((release) => release.version)
        .find(
            (release) =>
                release === 'rolling' ||
                version === release ||
                version.startsWith(`${release}.`),
        );
}

/**
 * The select's options: every listed release grouped by family, the
 * template's own version when no listed release covers it, "Other…" last.
 */
export function releaseOptions(
    distributions: Distribution[],
    families: Family[],
    translate: (key: string) => string,
    current: { distribution: string; version: string },
): { value: string; label: string; group?: string }[] {
    const familyLabel = (value: string) =>
        translate(
            families.find((family) => family.value === value)?.label ?? value,
        );
    const options: { value: string; label: string; group?: string }[] = [];

    for (const distribution of distributions) {
        const group = familyLabel(distribution.family);
        const versions = distribution.releases.map(
            (release) => release.version,
        );

        if (
            distribution.id === current.distribution &&
            current.version !== '' &&
            releaseOf(distribution, current.version) === undefined
        ) {
            versions.push(current.version);
        }

        for (const version of versions) {
            options.push({
                value: releaseValue(distribution.id, version),
                label: suggestedName(distribution, distribution.id, version),
                group,
            });
        }
    }

    return [...options, { value: OTHER, label: translate('Other…') }];
}

/** "Ubuntu 24.04 LTS", "Pardus 23.4": the name suggested until one is typed. */
export function suggestedName(
    distribution: Distribution | undefined,
    id: string,
    version: string,
): string {
    const name =
        distribution?.id === 'windows' && version.length === 4
            ? 'Windows Server'
            : (distribution?.name ?? id);
    const lts =
        distribution?.id === 'ubuntu' &&
        /^\d\d\.04$/.test(version) &&
        Number.parseInt(version, 10) % 2 === 0;

    const shown = version === 'rolling' ? '' : version;

    return `${name} ${shown}${lts ? ' LTS' : ''}`.trim();
}

/**
 * The list's system column: "Ubuntu 24.04 · Debian family", or for one
 * matched as another "Pardus 23.4 · Debian family · apps as Debian 12".
 */
export function systemParts(
    template: {
        distribution: string;
        version: string;
        family: string;
        compatible_with: string | null;
        compatible_version: string | null;
    },
    distributions: Distribution[],
): { system: string; family: string; matchedAs: string | null } {
    const name = (id: string) => find(distributions, id)?.name ?? id;

    return {
        system: `${name(template.distribution)} ${template.version === 'rolling' ? '' : template.version}`.trim(),
        family: template.family,
        matchedAs: template.compatible_with
            ? `${name(template.compatible_with)} ${template.compatible_version ?? ''}`.trim()
            : null,
    };
}

/** The fields of a template that say what it is. */
export type OsTemplateValues = {
    distribution: string;
    version: string;
    family: string;
    compatible_with: string | null;
    compatible_version: string | null;
};

/** GET os-templates/{template}/os-release (OsTemplateController::osRelease()). */
export type OsReleaseResult = {
    server: string;
    servers: { value: string; label: string }[];
    os_release: {
        id: string;
        id_like: string[];
        version_id: string | null;
        pretty_name: string | null;
    };
    suggestion: OsTemplateValues;
    differences: {
        field: keyof OsTemplateValues;
        template: string | null;
        server: string | null;
    }[];
};
