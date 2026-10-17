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
        capabilities?: string[];
        families?: string[];
        min_versions?: Record<string, string>;
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
];

export const OUTPUT_TYPES = ['text', 'url', 'file'];

export const CATEGORIES = ['vpn', 'control-panel', 'container', 'web'];

export const NETWORK_MODELS = ['bridged', 'routed', 'nat'];

/**
 * Fill the parts the forms bind to, so a format 2 definition from the
 * server (or a new one) can be edited field by field.
 */
export function editable(definition: RecipeDefinition): RecipeDefinition {
    const copy = JSON.parse(JSON.stringify(definition)) as RecipeDefinition;

    copy.description ??= { en: '' };
    copy.requires ??= { platform: 'linux' };
    copy.requires.capabilities ??= [];
    copy.requires.min_versions ??= {};
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
