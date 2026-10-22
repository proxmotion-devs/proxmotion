/**
 * The recipe.yaml shape the admin editor works on (docs/adr/0016, 0018).
 * The panel validates everything again; these types only drive the forms.
 */
export type RecipeText = { en: string; tr?: string };

export type RecipeInput = {
    key: string;
    type: string;
    label?: RecipeText;
    required?: boolean;
    default?: string | number | boolean;
    options?: string[];
    min?: number;
    max?: number;
    max_length?: number;
};

export type RecipeOutput = {
    key: string;
    type: string;
    label?: RecipeText;
    filename?: string;
    secret?: boolean;
};

export type RecipeStep = {
    script: string;
    timeout_minutes: number;
    from?: string;
};

export type RecipeAction = {
    key: string;
    label: RecipeText;
    script: string;
    inputs?: RecipeInput[];
    outputs?: RecipeOutput[];
    timeout_minutes?: number;
};

/** `requires.os` of format 2 (docs/adr/0024 §4). */
export type OsRequirement = { families?: string[]; distributions?: string[] };

export type RecipeDefinition = {
    format?: number;
    slug?: string;
    name: string;
    version?: string;
    category: string;
    description: RecipeText;
    maintainers?: { name: string; url?: string; email?: string }[];
    homepage?: string;
    requires: {
        platform: string;
        /** Families and distributions (docs/adr/0024 §4); none: any OS of the platform. */
        os?: OsRequirement;
        /** Read from older recipes only (with a list `os`); converted by editable(). */
        capabilities?: string[];
        families?: string[];
        min_versions?: Record<string, string>;
        max_versions?: Record<string, string>;
    };
    resources: {
        min_memory_mib: number;
        min_disk_mib: number;
        min_cores: number;
    };
    network_models: string[];
    install_modes: string[];
    billing: string;
    license: string;
    inputs?: RecipeInput[];
    outputs?: RecipeOutput[];
    firewall?: { port: string | number; proto: string }[];
    includes?: string[];
    lifecycle: {
        install: RecipeStep;
        uninstall?: RecipeStep;
        upgrade?: RecipeStep;
    };
    actions?: RecipeAction[];
    [key: string]: unknown;
};

export const INPUT_TYPES = [
    'string',
    'password',
    'integer',
    'port',
    'boolean',
    'enum',
    'slug',
    'email',
    'domain',
    'url',
];

export const OUTPUT_TYPES = ['text', 'url', 'file'];

export const CATEGORIES = ['vpn', 'control-panel', 'container', 'web'];

const CAPABILITY_GROUPS: Record<string, string> = {
    apt: 'debian',
    dnf: 'rhel',
    zypper: 'suse',
    pacman: 'arch',
};

/** Names of older list-form recipes that meant a family (docs/adr/0024 §4). */
const FAMILY_NAMES: Record<string, string> = {
    debian: 'debian',
    rhel: 'rhel',
    suse: 'suse',
    arch: 'arch',
    windows: 'windows',
    archlinux: 'arch',
    opensuse: 'suse',
};

/** Version keys of older recipes that are now os-release IDs. */
const OLD_DISTRIBUTIONS: Record<string, string> = {
    archlinux: 'arch',
    opensuse: 'opensuse-leap',
};

/**
 * The OS groups older recipes meant with package-manager capabilities
 * ("apt|dnf" → debian, rhel), like Recipe::operatingSystems().
 */
export function osFromCapabilities(capabilities: string[]): string[] {
    const groups = capabilities
        .flatMap((capability) => capability.split('|'))
        .map((name) => CAPABILITY_GROUPS[name])
        .filter((group): group is string => group !== undefined);

    return [...new Set(groups)];
}

/**
 * The list form of older recipes as families and distributions: the group
 * names were families, other names distributions (Recipe::operatingSystems()).
 */
export function osFromList(names: string[]): OsRequirement | undefined {
    const families = new Set<string>();
    const distributions = new Set<string>();

    for (const name of names.map((value) => value.toLowerCase())) {
        if (name in FAMILY_NAMES) {
            families.add(FAMILY_NAMES[name]);
        } else {
            distributions.add(name);
        }
    }

    if (families.size === 0 && distributions.size === 0) {
        return undefined;
    }

    return {
        ...(families.size ? { families: [...families] } : {}),
        ...(distributions.size ? { distributions: [...distributions] } : {}),
    };
}

function renamedLimits(
    limits: Record<string, string> | undefined,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(limits ?? {}).map(([name, version]) => [
            OLD_DISTRIBUTIONS[name] ?? name,
            version,
        ]),
    );
}

/**
 * Fill the parts the forms bind to, so a format 2 definition from the
 * server (or a new one) can be edited field by field.
 */
export function editable(definition: RecipeDefinition): RecipeDefinition {
    const copy = JSON.parse(JSON.stringify(definition)) as RecipeDefinition;

    copy.description ??= { en: '' };
    copy.requires ??= { platform: 'linux' };
    // Older recipes: a list `os`, `families`, or package managers as capabilities (docs/adr/0024 §4).
    const legacy = copy.requires as RecipeDefinition['requires'] & {
        os?: OsRequirement | string[];
    };
    const os = Array.isArray(legacy.os)
        ? osFromList(legacy.os)
        : (legacy.os ??
          osFromList(
              legacy.families ?? osFromCapabilities(legacy.capabilities ?? []),
          ));

    if (os) {
        copy.requires.os = os;
    } else {
        delete copy.requires.os;
    }

    delete copy.requires.families;
    delete copy.requires.capabilities;
    copy.requires.min_versions = renamedLimits(copy.requires.min_versions);
    copy.requires.max_versions = renamedLimits(copy.requires.max_versions);
    copy.resources ??= {
        min_memory_mib: 512,
        min_disk_mib: 5120,
        min_cores: 1,
    };
    copy.network_models ??= [];
    copy.install_modes ??= [];
    copy.inputs ??= [];
    copy.outputs ??= [];
    copy.includes ??= [];
    copy.actions ??= [];
    copy.lifecycle ??= {
        install: { script: 'install.sh', timeout_minutes: 10 },
    };

    for (const action of copy.actions) {
        action.inputs ??= [];
        action.outputs ??= [];
    }

    return copy;
}

/**
 * Drop what the forms leave empty (blank texts, empty lists and maps), so
 * optional fields are left out instead of failing the schema.
 */
export function pruned<T>(value: T): T {
    if (Array.isArray(value)) {
        return value
            .map((item) => pruned(item))
            .filter((item) => !isEmpty(item)) as T;
    }

    if (value !== null && typeof value === 'object') {
        const result: Record<string, unknown> = {};

        for (const [key, item] of Object.entries(value)) {
            const clean = pruned(item);

            if (!isEmpty(clean)) {
                result[key] = clean;
            }
        }

        return result as T;
    }

    return value;
}

function isEmpty(value: unknown): boolean {
    if (value === null || value === undefined || value === '') {
        return true;
    }

    if (typeof value === 'number') {
        return Number.isNaN(value);
    }

    if (Array.isArray(value)) {
        return value.length === 0;
    }

    return typeof value === 'object' && Object.keys(value).length === 0;
}

/**
 * The language of a recipe file for the code editor.
 */
export function languageOf(path: string): 'shell' | 'powershell' | 'yaml' {
    if (path.endsWith('.ps1')) {
        return 'powershell';
    }

    return path.endsWith('.yaml') ? 'yaml' : 'shell';
}
