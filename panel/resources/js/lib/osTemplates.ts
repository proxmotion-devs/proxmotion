/**
 * The administrators' operating systems and versions (docs/adr/0026): OS
 * templates are of one version, and apps are ticked for the versions they
 * are offered on.
 */

import type { OsPlatform } from '@/types/admin';

/** A version of a system (OsTemplateController::index()). */
export type SystemVersion = {
    id: number;
    version: string;
    /** How many OS templates use it; such a version cannot be deleted. */
    templates: number;
};

/** An operating system with its versions, newest first. */
export type OperatingSystem = {
    id: number;
    name: string;
    platform: OsPlatform;
    versions: SystemVersion[];
};

/**
 * A known os-release ID, for the recipe editor's OS picker
 * (Distributions::forForm()).
 */
export type Distribution = {
    id: string;
    name: string;
    family: string;
    base: string | null;
};

/** "Ubuntu 24.04". */
export function versionLabel(
    system: { name: string },
    version: string,
): string {
    return `${system.name} ${version}`.trim();
}

/** The template form's select: every version, grouped by system. */
export function versionOptions(
    systems: OperatingSystem[],
): { value: number; label: string; group: string }[] {
    return systems.flatMap((system) =>
        system.versions.map((version) => ({
            value: version.id,
            label: versionLabel(system, version.version),
            group: system.name,
        })),
    );
}

/** The system and version of a version id. */
export function findVersion(
    systems: OperatingSystem[],
    id: number | null | undefined,
): { system: OperatingSystem; version: SystemVersion } | undefined {
    for (const system of systems) {
        const version = system.versions.find((entry) => entry.id === id);

        if (version) {
            return { system, version };
        }
    }

    return undefined;
}

/** "Ubuntu 24.04 LTS", "Windows Server 2022": the name suggested until one is typed. */
export function suggestedName(
    system: { name: string } | undefined,
    version: string,
): string {
    if (!system) {
        return '';
    }

    const lts =
        /^ubuntu$/i.test(system.name) &&
        /^\d\d\.04$/.test(version) &&
        Number.parseInt(version, 10) % 2 === 0;

    return `${versionLabel(system, version)}${lts ? ' LTS' : ''}`;
}

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
    /** "Ubuntu 24.04": the template's system and version. */
    template_system: string;
    /** What the server calls itself (PRETTY_NAME). */
    server_system: string;
    /** Whether VERSION_ID agrees with the template's version; null when the server has none. */
    version_matches: boolean | null;
};
