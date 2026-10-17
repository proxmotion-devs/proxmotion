<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Copy,
    FilePlus,
    Plus,
    RotateCcw,
    Save,
    Trash2,
    Upload,
} from '@lucide/vue';
import { dump, load } from 'js-yaml';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import CodeEditor from '@/components/apps/CodeEditor.vue';
import RecipeFieldsEditor from '@/components/apps/RecipeFieldsEditor.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useDateTime } from '@/composables/useDateTime';
import {
    CATEGORIES,
    NETWORK_MODELS,
    editable,
    expandOs,
    languageOf,
    pruned,
} from '@/lib/recipes';
import type {
    RecipeAction,
    RecipeDefinition,
    RecipeInput,
    RecipeOutput,
} from '@/lib/recipes';
import {
    copy as copyRoute,
    destroy,
    diff as diffRoute,
    index,
    publish as publishRoute,
    revert as revertRoute,
} from '@/routes/admin/apps';
import { discard, save } from '@/routes/admin/apps/draft';

type Snapshot = {
    version: string;
    definition: RecipeDefinition;
    files: Record<string, string>;
};

/**
 * The admin recipe editor (docs/adr/0018 §5): the draft of a custom app,
 * or the published version read-only (bundled apps, which can be copied).
 */
const props = defineProps<{
    app: {
        slug: string;
        name: string;
        source: 'bundled' | 'custom';
        is_active: boolean;
        editable: boolean;
        installed: boolean;
        copied_from: { slug: string; name: string; version: string } | null;
        upstream_version: string | null;
        /** A bundled app an administrator edited in place. */
        modified: boolean;
        bundled_version: string | null;
        /** A bundled release newer than the one the edit started from. */
        bundled_newer: boolean;
    };
    draft: (Snapshot & { errors: string[] }) | null;
    published: Snapshot | null;
    versions: {
        version: string;
        status:
            | 'draft'
            | 'published'
            | 'archived'
            | 'pending_review'
            | 'rejected';
        published_by: string | null;
        published_at: string | null;
        created_at: string | null;
    }[];
    osGroups: Record<string, { families: string[]; os_release: string[] }>;
    families: string[];
    library: Record<string, string>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Apps', href: index() }] },
});

const page = usePage<{ errors: Record<string, string> }>();
const { formatDateTime } = useDateTime();

const readonly = computed(() => !props.app.editable);
const source = (): Snapshot | null => props.draft ?? props.published;

const definition = ref<RecipeDefinition>(
    editable((source()?.definition ?? {}) as RecipeDefinition),
);
const files = ref<Record<string, string>>({ ...source()?.files });
const formatTwo = computed(() => (definition.value.format ?? 1) === 2);

// Reload the forms from the server after a save, publish or discard.
const reset = () => {
    definition.value = editable(
        (source()?.definition ?? {}) as RecipeDefinition,
    );
    files.value = { ...source()?.files };
    yamlText.value = dump(pruned(definition.value), { lineWidth: 120 });
    yamlError.value = null;
};

const tabs = [
    ['general', 'General'],
    ['compatibility', 'Compatibility'],
    ['fields', 'Inputs and outputs'],
    ['scripts', 'Scripts'],
    ['actions', 'Actions'],
    ['yaml', 'YAML'],
    ['history', 'History'],
] as const;
type Tab = (typeof tabs)[number][0];
const tab = ref<Tab>(formatTwo.value ? 'general' : 'yaml');

// YAML view: the whole definition as text, read back when leaving the tab.
const yamlText = ref(dump(pruned(definition.value), { lineWidth: 120 }));
const yamlError = ref<string | null>(null);

const readYaml = (): boolean => {
    try {
        const parsed = load(yamlText.value);

        if (
            parsed === null ||
            typeof parsed !== 'object' ||
            Array.isArray(parsed)
        ) {
            yamlError.value = 'recipe.yaml must be a mapping.';

            return false;
        }

        definition.value = editable(parsed as RecipeDefinition);
        yamlError.value = null;

        return true;
    } catch (error) {
        yamlError.value =
            error instanceof Error ? error.message : String(error);

        return false;
    }
};

const switchTab = (next: Tab) => {
    if (tab.value === 'yaml' && !readonly.value && !readYaml()) {
        return;
    }

    if (next === 'yaml') {
        yamlText.value = dump(pruned(definition.value), { lineWidth: 120 });
    }

    tab.value = next;
};

// Files
const fileNames = computed(() => Object.keys(files.value).sort());
const selectedFile = ref<string | null>(
    fileNames.value.includes('install.sh')
        ? 'install.sh'
        : (fileNames.value[0] ?? null),
);
const newFile = ref('');
const fileOptions = computed(() =>
    fileNames.value.map((name) => ({ value: name, label: name })),
);
const uninstallOptions = computed(() => [
    { value: '', label: '—' },
    ...fileOptions.value,
]);

const addFile = () => {
    const name = newFile.value.trim();

    if (name === '' || name in files.value) {
        return;
    }

    files.value = {
        ...files.value,
        [name]: name.endsWith('.ps1')
            ? '$ErrorActionPreference = "Stop"\n'
            : '#!/bin/bash\nset -euo pipefail\n# shellcheck source=/dev/null\n. "$PROXMOTION_APP_DIR/proxmotion.sh"\n',
    };
    selectedFile.value = name;
    newFile.value = '';
};

const removeFile = (name: string) => {
    const next = { ...files.value };
    delete next[name];
    files.value = next;
    selectedFile.value = fileNames.value[0] ?? null;
};

const selectedContent = computed({
    get: () =>
        selectedFile.value === null
            ? ''
            : (files.value[selectedFile.value] ??
              props.library[selectedFile.value.replace('_lib/', '')] ??
              ''),
    set: (value: string) => {
        if (selectedFile.value !== null && selectedFile.value in files.value) {
            files.value[selectedFile.value] = value;
        }
    },
});

const uninstallScript = computed({
    get: () => definition.value.lifecycle.uninstall?.script ?? '',
    set: (value: string) => {
        definition.value.lifecycle.uninstall =
            value === ''
                ? undefined
                : {
                      script: value,
                      timeout_minutes:
                          definition.value.lifecycle.uninstall
                              ?.timeout_minutes ?? 5,
                  };
    },
});

const toggleInclude = (name: string, value: boolean | 'indeterminate') => {
    const includes = definition.value.includes ?? [];
    definition.value.includes =
        value === true
            ? [...includes, name]
            : includes.filter((existing) => existing !== name);
};

// Compatibility: template families or groups of them (docs/adr/0016 §3).
const osFamilies = computed(() =>
    props.families.filter(
        (family) => family !== 'windows' && family !== 'other',
    ),
);
const coveredFamilies = computed(() =>
    expandOs(definition.value.requires.os ?? [], props.osGroups),
);

const toggleList = (
    list: string[] | undefined,
    item: string,
    value: boolean | 'indeterminate',
): string[] =>
    value === true
        ? [...(list ?? []), item]
        : (list ?? []).filter((existing) => existing !== item);

const minVersions = computed(
    () => definition.value.requires.min_versions ?? {},
);
const maxVersions = computed(
    () => definition.value.requires.max_versions ?? {},
);

// Actions
const addAction = () => {
    definition.value.actions = [
        ...(definition.value.actions ?? []),
        {
            key: '',
            label: { en: '', tr: '' },
            script: fileNames.value[0] ?? '',
            inputs: [],
            outputs: [],
            timeout_minutes: 5,
        },
    ];
};

const removeAction = (position: number) => {
    definition.value.actions = (definition.value.actions ?? []).filter(
        (_, index) => index !== position,
    );
};

const actionFields = (action: RecipeAction, kind: 'inputs' | 'outputs') =>
    (action[kind] ??= []) as (RecipeInput | RecipeOutput)[];

// Saving, publishing, copying
const saving = ref(false);

const saveDraft = () => {
    if (tab.value === 'yaml' && !readYaml()) {
        return;
    }

    router.put(
        save(props.app.slug),
        {
            // The panel reads recipe.yaml as YAML text, like the YAML view.
            yaml: dump(pruned(definition.value), { lineWidth: 120 }),
            files: files.value,
        },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onSuccess: reset,
        },
    );
};

const revertToBundled = () => {
    if (
        window.confirm(
            trans(
                'Go back to the bundled version :version? Your changes stay in the history; installations keep their version.',
                { version: props.app.bundled_version ?? '' },
            ),
        )
    ) {
        router.post(revertRoute(props.app.slug), {}, { preserveScroll: true });
    }
};

const discardDraft = () =>
    router.delete(discard(props.app.slug), {
        preserveScroll: true,
        onSuccess: reset,
    });

const publishOpen = ref(false);
const publishForm = useForm({ bump: 'patch' as 'patch' | 'minor' | 'major' });
const bumps = [
    { value: 'patch' as const, label: 'Patch (fixes)' },
    { value: 'minor' as const, label: 'Minor (new features)' },
    { value: 'major' as const, label: 'Major (breaking changes)' },
];

const submitPublish = () =>
    publishForm.submit(publishRoute(props.app.slug), {
        preserveScroll: true,
        onSuccess: () => {
            publishOpen.value = false;
            reset();
        },
    });

const copyOpen = ref(false);
const copyForm = useForm({ slug: '', name: '' });

const openCopy = () => {
    copyForm.clearErrors();
    copyForm.slug = `${props.app.slug}-custom`;
    copyForm.name = `${props.app.name} (custom)`;
    copyOpen.value = true;
};

const submitCopy = () =>
    copyForm.submit(copyRoute(props.app.slug), {
        onSuccess: () => (copyOpen.value = false),
    });

// History
const versionOptions = computed(() =>
    props.versions.map((version) => ({
        value: version.status === 'draft' ? 'draft' : version.version,
        label:
            version.status === 'draft'
                ? `${version.version} (draft)`
                : version.version,
    })),
);
const diffFrom = ref<string | null>(versionOptions.value[1]?.value ?? null);
const diffTo = ref<string | null>(versionOptions.value[0]?.value ?? null);
const diffText = ref<string | null>(null);

const loadDiff = async () => {
    if (diffFrom.value === null || diffTo.value === null) {
        return;
    }

    const response = await fetch(
        diffRoute.url(props.app.slug, {
            query: { from: diffFrom.value, to: diffTo.value },
        }),
        { headers: { Accept: 'application/json' } },
    );

    diffText.value = response.ok
        ? ((await response.json()) as { diff: string }).diff
        : null;
};

const statusTone = {
    draft: 'warning',
    published: 'success',
    archived: 'neutral',
    pending_review: 'warning',
    rejected: 'danger',
} as const;
const statusLabels = {
    draft: 'Draft',
    published: 'Published',
    archived: 'Archived',
    pending_review: 'Pending review',
    rejected: 'Rejected',
} as const;
</script>

<template>
    <Head :title="app.name" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-2">
                <Link
                    :href="index()"
                    class="flex items-center gap-1 text-sm text-muted-foreground hover:underline"
                >
                    <ArrowLeft class="h-4 w-4" />
                    {{ $t('Apps') }}
                </Link>
                <Heading
                    :title="app.name"
                    description="Scripts run as root inside customers' servers. Only publish what you have tried on a test server."
                />
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-mono text-muted-foreground">{{
                        app.slug
                    }}</span>
                    <StatusBadge
                        :tone="app.source === 'custom' ? 'neutral' : 'success'"
                        :label="app.source === 'custom' ? 'Custom' : 'Bundled'"
                    />
                    <StatusBadge
                        v-if="published"
                        tone="success"
                        :label="`v${published.version}`"
                    />
                    <StatusBadge v-if="draft" tone="warning" label="Draft" />
                    <StatusBadge
                        v-if="app.modified"
                        tone="warning"
                        label="Modified"
                    />
                    <span
                        v-if="app.bundled_newer"
                        class="text-amber-600 dark:text-amber-400"
                        data-test="bundled-newer"
                    >
                        {{
                            $t(
                                'A newer bundled version is available: :version. Compare it in the history, then merge your changes or go back to it.',
                                { version: app.bundled_version ?? '' },
                            )
                        }}
                    </span>
                    <span v-if="app.copied_from" class="text-muted-foreground">
                        {{
                            $t('Copied from :app :version', {
                                app: app.copied_from.name,
                                version: app.copied_from.version,
                            })
                        }}
                    </span>
                    <span
                        v-if="app.upstream_version"
                        class="text-amber-600 dark:text-amber-400"
                    >
                        {{
                            $t('The original has a newer version: :version', {
                                version: app.upstream_version,
                            })
                        }}
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="openCopy">
                    <Copy class="h-4 w-4" />
                    {{ $t('Copy') }}
                </Button>
                <Button
                    v-if="app.modified"
                    variant="outline"
                    data-test="revert"
                    @click="revertToBundled"
                >
                    <RotateCcw class="h-4 w-4" />
                    {{ $t('Back to bundled version') }}
                </Button>
                <template v-if="app.editable">
                    <Button
                        v-if="draft && published"
                        variant="outline"
                        @click="discardDraft"
                    >
                        {{ $t('Discard draft') }}
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="saving"
                        data-test="save-draft"
                        @click="saveDraft"
                    >
                        <Save class="h-4 w-4" />
                        {{ $t('Save draft') }}
                    </Button>
                    <Button
                        :disabled="!draft || draft.errors.length > 0"
                        data-test="publish"
                        @click="publishOpen = true"
                    >
                        <Upload class="h-4 w-4" />
                        {{ $t('Publish') }}
                    </Button>
                    <ConfirmDelete
                        v-if="app.source === 'custom' && !app.installed"
                        with-label
                        :action="destroy(app.slug)"
                        :name="app.name"
                        description="The app and all its versions are deleted. This cannot be undone."
                    />
                </template>
            </div>
        </div>

        <div
            v-if="draft && draft.errors.length"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
            data-test="draft-errors"
        >
            <p class="font-medium">
                {{ $t('Fix these before publishing:') }}
            </p>
            <ul class="mt-1 list-disc pl-5">
                <li
                    v-for="error in draft.errors"
                    :key="error"
                    class="break-words"
                >
                    {{ error }}
                </li>
            </ul>
        </div>
        <p
            v-for="(error, key) in page.props.errors"
            :key="key"
            class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
        >
            {{ error }}
        </p>

        <nav class="flex flex-wrap gap-1 border-b">
            <button
                v-for="[key, label] in tabs"
                :key="key"
                type="button"
                class="-mb-px border-b-2 px-3 py-2 text-sm"
                :class="
                    tab === key
                        ? 'border-primary font-medium'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                :data-test="`tab-${key}`"
                @click="switchTab(key)"
            >
                {{ $t(label) }}
            </button>
        </nav>

        <p
            v-if="!formatTwo && tab !== 'yaml' && tab !== 'history'"
            class="text-sm text-muted-foreground"
        >
            {{ $t('This is a format 1 recipe; edit it in the YAML view.') }}
        </p>

        <!-- General -->
        <section v-else-if="tab === 'general'" class="grid max-w-3xl gap-4">
            <FormField label="Name" for="name">
                <Input
                    id="name"
                    v-model="definition.name"
                    :disabled="readonly"
                />
            </FormField>
            <FormField label="Category" for="category">
                <NativeSelect
                    id="category"
                    v-model="definition.category"
                    :options="
                        CATEGORIES.map((value) => ({ value, label: value }))
                    "
                    :disabled="readonly"
                />
            </FormField>
            <FormField label="Description (English)" for="description_en">
                <textarea
                    id="description_en"
                    v-model="definition.description.en"
                    rows="3"
                    class="rounded-md border bg-transparent px-3 py-2 text-sm"
                    :disabled="readonly"
                />
            </FormField>
            <FormField label="Description (Turkish)" for="description_tr">
                <textarea
                    id="description_tr"
                    v-model="definition.description.tr"
                    rows="3"
                    class="rounded-md border bg-transparent px-3 py-2 text-sm"
                    :disabled="readonly"
                />
            </FormField>
            <FormField label="Homepage" for="homepage">
                <Input
                    id="homepage"
                    v-model="definition.homepage as string"
                    placeholder="https://example.com"
                    :disabled="readonly"
                />
            </FormField>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField label="Billing" for="billing">
                    <NativeSelect
                        id="billing"
                        v-model="definition.billing"
                        :options="[
                            { value: 'free', label: 'free' },
                            { value: 'paid', label: 'paid' },
                        ]"
                        :disabled="readonly"
                    />
                </FormField>
                <FormField label="Licence" for="license">
                    <NativeSelect
                        id="license"
                        v-model="definition.license"
                        :options="[
                            { value: 'none', label: 'none' },
                            { value: 'external', label: 'external' },
                        ]"
                        :disabled="readonly"
                    />
                </FormField>
            </div>
        </section>

        <!-- Compatibility -->
        <section
            v-else-if="tab === 'compatibility'"
            class="grid max-w-3xl gap-4"
        >
            <FormField label="Platform" for="platform">
                <NativeSelect
                    id="platform"
                    v-model="definition.requires.platform"
                    :options="[
                        { value: 'linux', label: 'Linux' },
                        { value: 'windows', label: 'Windows' },
                    ]"
                    :disabled="readonly"
                />
            </FormField>
            <div class="grid gap-2">
                <span class="text-sm font-medium">{{
                    $t('Operating systems (none: any of the platform)')
                }}</span>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t(
                            'Groups work like ID_LIKE in /etc/os-release: debian covers Debian and Ubuntu, rhel covers AlmaLinux, Rocky, CentOS and Fedora. The server checks /etc/os-release again before a script runs.',
                        )
                    }}
                </p>
                <div class="flex flex-wrap gap-x-4 gap-y-2">
                    <label
                        v-for="(group, name) in osGroups"
                        :key="name"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="
                                (definition.requires.os ?? []).includes(
                                    String(name),
                                )
                            "
                            :disabled="readonly"
                            @update:model-value="
                                definition.requires.os = toggleList(
                                    definition.requires.os,
                                    String(name),
                                    $event,
                                )
                            "
                        />
                        <span class="font-mono text-xs">{{ name }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            group.families.join(', ')
                        }}</span>
                    </label>
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-2">
                    <label
                        v-for="family in osFamilies"
                        :key="family"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="
                                (definition.requires.os ?? []).includes(family)
                            "
                            :disabled="readonly"
                            @update:model-value="
                                definition.requires.os = toggleList(
                                    definition.requires.os,
                                    family,
                                    $event,
                                )
                            "
                        />
                        <span class="font-mono text-xs">{{ family }}</span>
                    </label>
                </div>
            </div>
            <div v-if="coveredFamilies.length" class="grid gap-2">
                <span class="text-sm font-medium">{{
                    $t('Lowest version (empty: any)')
                }}</span>
                <div class="flex flex-wrap gap-x-4 gap-y-2">
                    <label
                        v-for="family in coveredFamilies"
                        :key="family"
                        class="flex items-center gap-2 text-sm"
                    >
                        <span class="font-mono text-xs">{{ family }}</span>
                        <Input
                            :model-value="minVersions[family] ?? ''"
                            class="h-7 w-20"
                            :placeholder="$t('min.')"
                            :disabled="readonly"
                            @update:model-value="
                                definition.requires.min_versions = {
                                    ...minVersions,
                                    [family]: String($event),
                                }
                            "
                        />
                    </label>
                </div>
            </div>
            <div v-if="coveredFamilies.length" class="grid gap-2">
                <span class="text-sm font-medium">{{
                    $t('Highest version (empty: any)')
                }}</span>
                <div class="flex flex-wrap gap-x-4 gap-y-2">
                    <label
                        v-for="family in coveredFamilies"
                        :key="family"
                        class="flex items-center gap-2 text-sm"
                    >
                        <span class="font-mono text-xs">{{ family }}</span>
                        <Input
                            :model-value="maxVersions[family] ?? ''"
                            class="h-7 w-20"
                            :placeholder="$t('max.')"
                            :disabled="readonly"
                            @update:model-value="
                                definition.requires.max_versions = {
                                    ...maxVersions,
                                    [family]: String($event),
                                }
                            "
                        />
                    </label>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <FormField label="Minimum vCPU cores" for="min_cores">
                    <Input
                        id="min_cores"
                        v-model.number="definition.resources.min_cores"
                        type="number"
                        min="1"
                        :disabled="readonly"
                    />
                </FormField>
                <FormField label="Minimum memory (MiB)" for="min_memory">
                    <Input
                        id="min_memory"
                        v-model.number="definition.resources.min_memory_mib"
                        type="number"
                        min="128"
                        :disabled="readonly"
                    />
                </FormField>
                <FormField label="Minimum disk (MiB)" for="min_disk">
                    <Input
                        id="min_disk"
                        v-model.number="definition.resources.min_disk_mib"
                        type="number"
                        min="1024"
                        :disabled="readonly"
                    />
                </FormField>
            </div>
            <div class="flex flex-wrap gap-8">
                <div class="grid gap-2">
                    <span class="text-sm font-medium">{{
                        $t('Network models')
                    }}</span>
                    <label
                        v-for="model in NETWORK_MODELS"
                        :key="model"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="
                                definition.network_models.includes(model)
                            "
                            :disabled="readonly"
                            @update:model-value="
                                definition.network_models = toggleList(
                                    definition.network_models,
                                    model,
                                    $event,
                                )
                            "
                        />
                        {{ model }}
                    </label>
                </div>
                <div class="grid gap-2">
                    <span class="text-sm font-medium">{{
                        $t('Install modes')
                    }}</span>
                    <label
                        v-for="mode in ['order', 'post']"
                        :key="mode"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="
                                definition.install_modes.includes(mode)
                            "
                            :disabled="readonly"
                            @update:model-value="
                                definition.install_modes = toggleList(
                                    definition.install_modes,
                                    mode,
                                    $event,
                                )
                            "
                        />
                        {{
                            mode === 'order'
                                ? $t('At order time')
                                : $t('Later, on a running server')
                        }}
                    </label>
                </div>
            </div>
        </section>

        <!-- Inputs and outputs -->
        <section v-else-if="tab === 'fields'" class="grid gap-6">
            <div class="grid gap-2">
                <h3 class="text-sm font-medium">{{ $t('Inputs') }}</h3>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t(
                            'What the customer fills in. Scripts read them with pm_input; they are validated and never put into a script.',
                        )
                    }}
                </p>
                <RecipeFieldsEditor
                    v-model="
                        definition.inputs as (RecipeInput | RecipeOutput)[]
                    "
                    kind="inputs"
                    :readonly="readonly"
                />
            </div>
            <div class="grid gap-2">
                <h3 class="text-sm font-medium">{{ $t('Outputs') }}</h3>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t(
                            'What the customer gets: texts and URLs written with pm_output, files with pm_output_file.',
                        )
                    }}
                </p>
                <RecipeFieldsEditor
                    v-model="
                        definition.outputs as (RecipeInput | RecipeOutput)[]
                    "
                    kind="outputs"
                    :readonly="readonly"
                />
            </div>
        </section>

        <!-- Scripts -->
        <section
            v-else-if="tab === 'scripts'"
            class="grid gap-4 lg:grid-cols-[16rem_1fr]"
        >
            <div class="flex flex-col gap-3">
                <div class="flex flex-col gap-1">
                    <button
                        v-for="name in fileNames"
                        :key="name"
                        type="button"
                        class="flex items-center justify-between rounded-md px-2 py-1 text-left font-mono text-xs"
                        :class="
                            selectedFile === name
                                ? 'bg-muted font-medium'
                                : 'hover:bg-muted/60'
                        "
                        @click="selectedFile = name"
                    >
                        {{ name }}
                        <Trash2
                            v-if="!readonly"
                            class="h-3.5 w-3.5 text-muted-foreground hover:text-destructive"
                            :aria-label="$t('Remove')"
                            @click.stop="removeFile(name)"
                        />
                    </button>
                    <button
                        v-for="name in Object.keys(library)"
                        :key="`_lib/${name}`"
                        type="button"
                        class="rounded-md px-2 py-1 text-left font-mono text-xs text-muted-foreground"
                        :class="
                            selectedFile === `_lib/${name}`
                                ? 'bg-muted'
                                : 'hover:bg-muted/60'
                        "
                        @click="selectedFile = `_lib/${name}`"
                    >
                        _lib/{{ name }} ({{ $t('read-only') }})
                    </button>
                </div>
                <div v-if="!readonly" class="flex gap-2">
                    <Input
                        v-model="newFile"
                        class="h-8 font-mono text-xs"
                        placeholder="actions/add-user.sh"
                        @keydown.enter.prevent="addFile"
                    />
                    <Button
                        variant="outline"
                        size="icon"
                        :aria-label="$t('Add file')"
                        @click="addFile"
                    >
                        <FilePlus class="h-4 w-4" />
                    </Button>
                </div>

                <div class="grid gap-3 border-t pt-3 text-sm">
                    <FormField label="Install script" for="install_script">
                        <NativeSelect
                            id="install_script"
                            v-model="definition.lifecycle.install.script"
                            :options="fileOptions"
                            :disabled="readonly"
                        />
                    </FormField>
                    <FormField
                        label="Install timeout (minutes)"
                        for="install_timeout"
                    >
                        <Input
                            id="install_timeout"
                            v-model.number="
                                definition.lifecycle.install.timeout_minutes
                            "
                            type="number"
                            min="1"
                            max="120"
                            :disabled="readonly"
                        />
                    </FormField>
                    <FormField label="Uninstall script" for="uninstall_script">
                        <NativeSelect
                            id="uninstall_script"
                            v-model="uninstallScript"
                            :options="uninstallOptions"
                            :disabled="readonly"
                        />
                    </FormField>
                    <div class="grid gap-1">
                        <span class="font-medium">{{
                            $t('Shared files (includes)')
                        }}</span>
                        <label
                            v-for="name in fileNames"
                            :key="`include-${name}`"
                            class="flex items-center gap-2 font-mono text-xs"
                        >
                            <Checkbox
                                :model-value="
                                    (definition.includes ?? []).includes(name)
                                "
                                :disabled="readonly"
                                @update:model-value="
                                    toggleInclude(name, $event)
                                "
                            />
                            {{ name }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="min-w-0">
                <CodeEditor
                    v-if="selectedFile !== null"
                    :key="selectedFile"
                    v-model="selectedContent"
                    :language="languageOf(selectedFile)"
                    :readonly="readonly || selectedFile.startsWith('_lib/')"
                    min-height="28rem"
                />
                <p v-else class="text-sm text-muted-foreground">
                    {{ $t('No files yet.') }}
                </p>
            </div>
        </section>

        <!-- Actions -->
        <section v-else-if="tab === 'actions'" class="grid gap-4">
            <p class="text-sm text-muted-foreground">
                {{
                    $t(
                        'Actions run on an installed app, e.g. adding a VPN client. Each has its own script, inputs and outputs.',
                    )
                }}
            </p>
            <div
                v-for="(action, position) in definition.actions"
                :key="position"
                class="grid gap-3 rounded-xl border p-4"
            >
                <div class="grid gap-3 sm:grid-cols-4">
                    <Input
                        v-model="action.key"
                        class="font-mono"
                        :placeholder="$t('Key, e.g. add_client')"
                        :disabled="readonly"
                    />
                    <Input
                        v-model="action.label.en"
                        :placeholder="$t('Label (English)')"
                        :disabled="readonly"
                    />
                    <Input
                        v-model="action.label.tr"
                        :placeholder="$t('Label (Turkish)')"
                        :disabled="readonly"
                    />
                    <div class="flex gap-2">
                        <NativeSelect
                            v-model="action.script"
                            :options="fileOptions"
                            :disabled="readonly"
                        />
                        <Button
                            v-if="!readonly"
                            variant="ghost"
                            size="icon"
                            :aria-label="$t('Remove')"
                            @click="removeAction(position)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
                <FormField
                    label="Timeout (minutes)"
                    :for="`action_timeout_${position}`"
                >
                    <Input
                        :id="`action_timeout_${position}`"
                        v-model.number="action.timeout_minutes"
                        type="number"
                        min="1"
                        max="120"
                        class="w-28"
                        :disabled="readonly"
                    />
                </FormField>
                <span class="text-sm font-medium">{{ $t('Inputs') }}</span>
                <RecipeFieldsEditor
                    :model-value="actionFields(action, 'inputs')"
                    kind="inputs"
                    :readonly="readonly"
                    @update:model-value="
                        action.inputs = $event as RecipeInput[]
                    "
                />
                <span class="text-sm font-medium">{{ $t('Outputs') }}</span>
                <RecipeFieldsEditor
                    :model-value="actionFields(action, 'outputs')"
                    kind="outputs"
                    :readonly="readonly"
                    @update:model-value="
                        action.outputs = $event as RecipeOutput[]
                    "
                />
            </div>
            <div v-if="!readonly">
                <Button variant="outline" size="sm" @click="addAction">
                    <Plus class="h-4 w-4" />
                    {{ $t('Add action') }}
                </Button>
            </div>
        </section>

        <!-- YAML -->
        <section v-else-if="tab === 'yaml'" class="grid gap-2">
            <p class="text-xs text-muted-foreground">
                {{
                    $t(
                        'The whole recipe.yaml. Changes here replace the forms when you leave this view or save.',
                    )
                }}
            </p>
            <p
                v-if="yamlError"
                class="rounded-lg border border-red-200 bg-red-50 p-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
            >
                {{ yamlError }}
            </p>
            <CodeEditor
                v-model="yamlText"
                language="yaml"
                :readonly="readonly"
                min-height="32rem"
            />
        </section>

        <!-- History -->
        <section v-else class="grid gap-4">
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">
                                {{ $t('Version') }}
                            </th>
                            <th class="px-4 py-3 font-medium">
                                {{ $t('Status') }}
                            </th>
                            <th class="px-4 py-3 font-medium">
                                {{ $t('Published by') }}
                            </th>
                            <th class="px-4 py-3 font-medium">
                                {{ $t('Date') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="version in versions"
                            :key="`${version.version}-${version.status}`"
                            class="border-t"
                        >
                            <td class="px-4 py-3 font-mono">
                                {{ version.version }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusBadge
                                    :tone="statusTone[version.status]"
                                    :label="statusLabels[version.status]"
                                />
                            </td>
                            <td class="px-4 py-3">
                                {{ version.published_by ?? '—' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{
                                    formatDateTime(
                                        version.published_at ??
                                            version.created_at,
                                    )
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="versionOptions.length > 1"
                class="flex flex-wrap items-end gap-2"
            >
                <FormField label="From" for="diff_from">
                    <NativeSelect
                        id="diff_from"
                        v-model="diffFrom"
                        :options="versionOptions"
                    />
                </FormField>
                <FormField label="To" for="diff_to">
                    <NativeSelect
                        id="diff_to"
                        v-model="diffTo"
                        :options="versionOptions"
                    />
                </FormField>
                <Button variant="outline" @click="loadDiff">{{
                    $t('Compare')
                }}</Button>
            </div>
            <pre
                v-if="diffText !== null"
                class="max-h-[32rem] overflow-auto rounded-lg border bg-muted/30 p-3 font-mono text-xs"
                data-test="version-diff"
            ><template v-for="(line, number) in diffText.split('\n')" :key="number"><span :class="line.startsWith('+') ? 'text-emerald-600 dark:text-emerald-400' : line.startsWith('-') ? 'text-red-600 dark:text-red-400' : line.startsWith('@@') ? 'text-sky-600 dark:text-sky-400' : ''">{{ line }}</span>
</template></pre>
            <p v-if="diffText === ''" class="text-sm text-muted-foreground">
                {{ $t('No differences.') }}
            </p>
        </section>
    </div>

    <FormDialog
        v-model:open="publishOpen"
        title="Publish the draft"
        description="New installations get the published version at once. Installed copies keep their version."
        submit-label="Publish"
        :processing="publishForm.processing"
        @submit="submitPublish"
    >
        <FormField
            v-if="app.source === 'custom'"
            label="Version change"
            for="bump"
            :error="publishForm.errors.bump"
        >
            <NativeSelect
                id="bump"
                v-model="publishForm.bump"
                :options="bumps"
            />
        </FormField>
        <p v-else class="text-sm text-muted-foreground">
            {{
                $t(
                    'Your edit of a bundled app is numbered after its bundled version (e.g. 1.2.1-local.1). Panel updates keep it; a newer bundled version is offered here to compare.',
                )
            }}
        </p>
    </FormDialog>

    <FormDialog
        v-model:open="copyOpen"
        title="Copy the app"
        description="The copy is a new custom app with its own slug, starting as a draft of version 1.0.0. Panel updates never change it."
        submit-label="Copy"
        :processing="copyForm.processing"
        @submit="submitCopy"
    >
        <FormField label="Slug" for="copy_slug" :error="copyForm.errors.slug">
            <Input id="copy_slug" v-model="copyForm.slug" class="font-mono" />
        </FormField>
        <FormField label="Name" for="copy_name" :error="copyForm.errors.name">
            <Input id="copy_name" v-model="copyForm.name" />
        </FormField>
    </FormDialog>
</template>
