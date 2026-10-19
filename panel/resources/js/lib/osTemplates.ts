/**
 * The OS template form's catalog of distributions (docs/adr/0024): what a
 * template is (its os-release ID and version), its family, and the
 * distribution apps are matched as when it has its own version numbers.
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
    example: string;
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

/** The select's options, grouped by family, with "Other…" last. */
export function distributionOptions(
    distributions: Distribution[],
    families: Family[],
    translate: (key: string) => string,
): { value: string; label: string; group?: string }[] {
    const familyLabel = (value: string) =>
        translate(
            families.find((family) => family.value === value)?.label ?? value,
        );

    return [
        ...distributions.map((distribution) => ({
            value: distribution.id,
            label: distribution.name,
            group: familyLabel(distribution.family),
        })),
        { value: OTHER, label: translate('Other…') },
    ];
}

/**
 * The distributions a template can be compatible with: known, not built on
 * another one, of the same family (none for Windows and "other").
 */
export function compatibleOptions(
    distributions: Distribution[],
    family: string,
): Distribution[] {
    return distributions.filter(
        (distribution) =>
            distribution.base === null &&
            distribution.family === family &&
            family !== 'windows' &&
            family !== 'other',
    );
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

    return `${name} ${version}${lts ? ' LTS' : ''}`.trim();
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
        system: `${name(template.distribution)} ${template.version}`.trim(),
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
