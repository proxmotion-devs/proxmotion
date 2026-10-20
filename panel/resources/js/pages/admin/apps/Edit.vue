<script setup lang="ts">
import AppSystemsPicker from '@/components/admin/AppSystemsPicker.vue';
import type { AppOsVersion } from '@/components/admin/AppSystemsPicker.vue';
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Copy,
    Download,
    FileCode,
    FilePlus,
    FileUp,
    Play,
    Plus,
    RotateCcw,
    Save,
    Trash2,
    Upload,
} from '@lucide/vue';
import { dump, load } from 'js-yaml';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import GuideBox from '@/components/admin/GuideBox.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import CodeEditor from '@/components/apps/CodeEditor.vue';
import GbInput from '@/components/apps/GbInput.vue';
import RecipeFieldsEditor from '@/components/apps/RecipeFieldsEditor.vue';
import Heading from '@/components/Heading.vue';
import AppInputFields from '@/components/servers/AppInputFields.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useDateTime } from '@/composables/useDateTime';
import { CATEGORIES, editable, languageOf, pruned } from '@/lib/recipes';
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
    exportMethod as exportRoute,
    index,
    publish as publishRoute,
    revert as revertRoute,
    trial as trialRoute,
} from '@/routes/admin/apps';
import {
    discard,
    importMethod as importDraftRoute,
    save,
} from '@/routes/admin/apps/draft';
import { show as showServer } from '@/routes/admin/servers';
import { NETWORK_MODELS } from '@/types/admin';
import type { AppInput, AppInstallationStatus } from '@/types/admin';

/** A trial installation of the app on a test server (docs/adr/0018 §7). */
type Trial = {
    uuid: string;
    server: { uuid: string; hostname: string };
    version: string;
    status: AppInstallationStatus;
    progress: number;
    step: string | null;
    error: string | null;
    /** The draft has not changed since this trial. */
    current: boolean;
    created_at: string | null;
    finished_at: string | null;
};

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
    /** The operating system versions apps can be ticked for (docs/adr/0026). */
    osVersions: AppOsVersion[];
    /** The app's ticks and what its recipe suggests; saved at once, not with the draft. */
    systems: {
        slug: string;
        platform: string;
        suggested_ids: number[];
        os_version_ids: number[];
    };
    library: Record<string, string>;
    trials: Trial[];
    /** Active test servers, with the reason when the draft does not fit one. */
    testServers: { uuid: string; hostname: string; problem: string | null }[];
    /** Inputs of the saved draft; empty while it has errors. */
    trialInputs: AppInput[];
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

// Five tabs from describing the app to trying it (docs/adr/0018 §5); the
// YAML view opens from the right of the tab bar instead.
const tabs = [
    ['details', 'Details'],
    ['requirements', 'Requirements'],
    ['form', 'Customer form'],
    ['scripts', 'Scripts & actions'],
    ['versions', 'Try & history'],
] as const;
type Tab = (typeof tabs)[number][0] | 'yaml';
const tab = ref<Tab>(formatTwo.value ? 'details' : 'yaml');
// The tab the YAML view goes back to.
const formTab = ref<Exclude<Tab, 'yaml'>>('details');

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
    } else {
        formTab.value = next;
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

// Requirements: network models the panel supports so far. NAT VPS comes
// with Phase 9; a recipe's `nat` stays as it is but is not offered here.
const networkModels = NETWORK_MODELS.filter((model) => model.value !== 'nat');

// Memory and disk presets in GB (stored in MiB).
const memoryPresets = [0.5, 1, 2, 4, 8];
const diskPresets = [5, 10, 20, 30, 50, 100];

const toggleList = (
    list: string[] | undefined,
    item: string,
    value: boolean | 'indeterminate',
): string[] =>
    value === true
        ? [...(list ?? []), item]
        : (list ?? []).filter((existing) => existing !== item);

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

// Trial runs of the saved draft on test servers (docs/adr/0018 §7).
const trialStatusLabels: Record<AppInstallationStatus, string> = {
    queued: 'Queued',
    installing: 'Installing',
    installed: 'Installed',
    failed: 'Failed',
    uninstalling: 'Uninstalling',
    uninstall_failed: 'Uninstall failed',
    upgrading: 'Upgrading',
    upgrade_failed: 'Upgrade failed',
    removed: 'Removed',
};
const trialStatusTone = (status: AppInstallationStatus) =>
    status === 'installed'
        ? 'success'
        : ['failed', 'uninstall_failed', 'upgrade_failed'].includes(status)
          ? 'danger'
          : ['queued', 'installing', 'uninstalling', 'upgrading'].includes(
                  status,
              )
            ? 'warning'
            : 'neutral';

// The saved draft was installed without errors by a trial.
const draftTried = computed(() =>
    props.trials.some((trial) => trial.current && trial.status === 'installed'),
);

const trialOpen = ref(false);
const trialForm = useForm<{
    server: string | null;
    inputs: Record<string, string | number | boolean>;
}>({ server: null, inputs: {} });

const serverOptions = computed(() =>
    props.testServers.map((server) => ({
        value: server.uuid,
        label: server.problem
            ? `${server.hostname} (${server.problem})`
            : server.hostname,
        disabled: server.problem !== null,
    })),
);

const openTrial = () => {
    trialForm.clearErrors();
    trialForm.server =
        props.testServers.find((server) => server.problem === null)?.uuid ??
        null;
    trialForm.inputs = Object.fromEntries(
        props.trialInputs
            .filter((input) => input.default !== null)
            .map((input) => [
                input.key,
                input.default as string | number | boolean,
            ]),
    );
    trialOpen.value = true;
};

const submitTrial = () =>
    trialForm.submit(trialRoute(props.app.slug), {
        preserveScroll: true,
        onSuccess: () => (trialOpen.value = false),
    });

// Follow running trials.
const { start: startPolling, stop: stopPolling } = usePoll(
    3000,
    { only: ['trials'] },
    { autoStart: false },
);
const trialsBusy = computed(() =>
    props.trials.some((trial) =>
        ['queued', 'installing', 'uninstalling', 'upgrading'].includes(
            trial.status,
        ),
    ),
);

watch(trialsBusy, (value) => (value ? startPolling() : stopPolling()), {
    immediate: true,
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

// Zip of a version's recipe directory (docs/adr/0018 §5): 'draft' or a version number.
const exportUrl = (version: string) =>
    exportRoute.url(props.app.slug, { query: { version } });

// Replace the draft with an uploaded recipe zip.
const importInput = ref<HTMLInputElement | null>(null);

const importDraft = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const archive = input.files?.item(0) ?? null;
    input.value = '';

    if (
        archive === null ||
        !window.confirm(
            trans(
                'Replace the draft with the recipe in :file? The published version stays as it is.',
                { file: archive.name },
            ),
        )
    ) {
        return;
    }

    router.post(
        importDraftRoute(props.app.slug),
        { archive },
        { forceFormData: true, preserveScroll: true, onSuccess: reset },
    );
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
                    v-if="draft || published"
                    variant="outline"
                    as-child
                    data-test="export"
                >
                    <a :href="exportUrl(draft ? 'draft' : published!.version)">
                        <Download class="h-4 w-4" />
                        {{ $t('Export zip') }}
                    </a>
                </Button>
                <template v-if="app.editable">
                    <input
                        ref="importInput"
                        type="file"
                        accept=".zip,application/zip"
                        class="hidden"
                        data-test="import-draft-file"
                        @change="importDraft"
                    />
                    <Button
                        variant="outline"
                        data-test="import-draft"
                        @click="importInput?.click()"
                    >
                        <FileUp class="h-4 w-4" />
                        {{ $t('Import zip') }}
                    </Button>
                </template>
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

        <GuideBox
            v-if="app.editable"
            id="app-editor"
            title="Writing a recipe"
            topic="apps-scripts"
        >
            <ul>
                <li>
                    {{
                        $t(
                            'Go through the tabs from left to right. Requirements decide which servers the app is offered for.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Scripts run as root: start with set -euo pipefail and make them safe to run twice.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Read inputs with pm_input KEY, report progress with pm_step and hand back results with pm_output. Never paste inputs into commands unquoted.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Save draft shows what to fix before publishing. Installed copies keep their version.',
                        )
                    }}
                </li>
            </ul>
        </GuideBox>

        <nav class="flex flex-wrap items-end justify-between gap-2 border-b">
            <div class="flex flex-wrap gap-1">
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
            </div>
            <button
                type="button"
                class="mb-1 inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                data-test="tab-yaml"
                @click="switchTab(tab === 'yaml' ? formTab : 'yaml')"
            >
                <FileCode class="h-3.5 w-3.5" />
                {{
                    tab === 'yaml' ? $t('Back to the form') : $t('Edit as YAML')
                }}
            </button>
        </nav>

        <p
            v-if="!formatTwo && tab !== 'yaml' && tab !== 'versions'"
            class="text-sm text-muted-foreground"
        >
            {{ $t('This is a format 1 recipe; edit it in the YAML view.') }}
        </p>

        <!-- Details -->
        <section v-else-if="tab === 'details'" class="grid max-w-3xl gap-4">
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
                            { value: 'free', label: trans('Free') },
                            {
                                value: 'paid',
                                label: trans(
                                    'Paid: needs a right granted by the billing system',
                                ),
                            },
                        ]"
                        :disabled="readonly"
                    />
                </FormField>
                <FormField label="Licence" for="license">
                    <NativeSelect
                        id="license"
                        v-model="definition.license"
                        :options="[
                            {
                                value: 'none',
                                label: trans('No licence needed'),
                            },
                            {
                                value: 'external',
                                label: trans(
                                    'Licence sold separately (e.g. cPanel, Plesk)',
                                ),
                            },
                        ]"
                        :disabled="readonly"
                    />
                </FormField>
            </div>
        </section>

        <!-- Requirements -->
        <section
            v-else-if="tab === 'requirements'"
            class="grid max-w-3xl gap-6"
        >
            <div class="grid gap-2">
                <h3 class="text-sm font-medium">
                    {{ $t('Operating systems') }}
                </h3>
                <AppSystemsPicker :app="systems" :os-versions="osVersions" />
            </div>

            <div class="grid gap-2">
                <h3 class="text-sm font-medium">
                    {{ $t('Smallest server it needs') }}
                </h3>
                <div class="grid gap-4 sm:grid-cols-3">
                    <FormField label="vCPU cores" for="min_cores">
                        <Input
                            id="min_cores"
                            v-model.number="definition.resources.min_cores"
                            type="number"
                            min="1"
                            class="w-28"
                            :disabled="readonly"
                        />
                    </FormField>
                    <FormField label="Memory" for="min_memory">
                        <GbInput
                            id="min_memory"
                            v-model="definition.resources.min_memory_mib"
                            :presets="memoryPresets"
                            :readonly="readonly"
                        />
                    </FormField>
                    <FormField label="Disk" for="min_disk">
                        <GbInput
                            id="min_disk"
                            v-model="definition.resources.min_disk_mib"
                            :presets="diskPresets"
                            :readonly="readonly"
                        />
                    </FormField>
                </div>
            </div>

            <div class="flex flex-wrap gap-8">
                <div class="grid gap-2">
                    <h3 class="text-sm font-medium">
                        {{ $t('Network models') }}
                    </h3>
                    <label
                        v-for="model in networkModels"
                        :key="model.value"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="
                                definition.network_models.includes(model.value)
                            "
                            :disabled="readonly"
                            @update:model-value="
                                definition.network_models = toggleList(
                                    definition.network_models,
                                    model.value,
                                    $event,
                                )
                            "
                        />
                        {{ $t(model.label) }}
                    </label>
                </div>
                <div class="grid gap-2">
                    <h3 class="text-sm font-medium">
                        {{ $t('When it can be installed') }}
                    </h3>
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
                                ? $t('With a new server or a reinstall')
                                : $t('Later, on a running server')
                        }}
                    </label>
                </div>
            </div>
        </section>

        <!-- Customer form: inputs and outputs -->
        <section v-else-if="tab === 'form'" class="grid gap-6">
            <div class="grid gap-2">
                <h3 class="text-sm font-medium">
                    {{ $t('What the customer fills in') }}
                </h3>
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
                <h3 class="text-sm font-medium">
                    {{ $t('What the customer gets back') }}
                </h3>
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

        <!-- Scripts & actions -->
        <template v-else-if="tab === 'scripts'">
            <section class="grid gap-4 lg:grid-cols-[16rem_1fr]">
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
                        <FormField
                            label="Uninstall script"
                            for="uninstall_script"
                        >
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
                                        (definition.includes ?? []).includes(
                                            name,
                                        )
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

            <section class="grid gap-4 border-t pt-6" data-test="actions">
                <h3 class="text-sm font-medium">{{ $t('Actions') }}</h3>
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
        </template>

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

        <!-- Try & history: trial runs (docs/adr/0018 §7) and versions -->
        <template v-else-if="tab === 'versions'">
            <section class="grid gap-4" data-test="trials">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <p class="max-w-2xl text-sm text-muted-foreground">
                        {{
                            $t(
                                'Install the draft as it was last saved on a test server. Billing systems never see trials; uninstall and actions of a trial use the copy it installed.',
                            )
                        }}
                    </p>
                    <div class="flex items-center gap-2">
                        <StatusBadge
                            v-if="draft"
                            :tone="draftTried ? 'success' : 'neutral'"
                            :label="
                                draftTried
                                    ? 'Saved draft tried'
                                    : 'Saved draft not tried yet'
                            "
                            data-test="draft-tried"
                        />
                        <Button
                            v-if="app.editable"
                            :disabled="
                                !draft ||
                                draft.errors.length > 0 ||
                                testServers.length === 0
                            "
                            data-test="try-draft"
                            @click="openTrial"
                        >
                            <Play class="h-4 w-4" />
                            {{ $t('Try on a test server') }}
                        </Button>
                    </div>
                </div>
                <p
                    v-if="testServers.length === 0"
                    class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
                >
                    {{
                        $t(
                            'No active test server yet. Mark a server without a billing system as a test server on its Apps tab.',
                        )
                    }}
                </p>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/50 text-left text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium">
                                    {{ $t('Server') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ $t('Version') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ $t('Status') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ $t('Date') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="trial in trials"
                                :key="trial.uuid"
                                class="border-t align-top"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        :href="showServer(trial.server.uuid)"
                                        class="font-medium hover:underline"
                                        >{{ trial.server.hostname }}</Link
                                    >
                                </td>
                                <td class="px-4 py-3 font-mono">
                                    {{ trial.version }}
                                    <span
                                        v-if="trial.current"
                                        class="ml-1 font-sans text-xs text-muted-foreground"
                                        >({{ $t('saved draft') }})</span
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    <StatusBadge
                                        :tone="trialStatusTone(trial.status)"
                                        :label="trialStatusLabels[trial.status]"
                                    />
                                    <p
                                        v-if="
                                            trial.step &&
                                            trial.status !== 'installed'
                                        "
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ trial.step }} · {{ trial.progress }}%
                                    </p>
                                    <p
                                        v-if="trial.error"
                                        class="mt-1 text-xs break-words text-red-700 dark:text-red-400"
                                    >
                                        {{ trial.error }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {{
                                        formatDateTime(
                                            trial.finished_at ??
                                                trial.created_at,
                                        )
                                    }}
                                </td>
                            </tr>
                            <tr v-if="trials.length === 0" class="border-t">
                                <td
                                    colspan="4"
                                    class="px-4 py-3 text-muted-foreground"
                                >
                                    {{ $t('No trial runs yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t(
                            'Logs, outputs, uninstall and actions of a trial are on the Apps tab of its server.',
                        )
                    }}
                </p>
            </section>

            <section class="grid gap-4 border-t pt-6" data-test="history">
                <h3 class="text-sm font-medium">{{ $t('Versions') }}</h3>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/50 text-left text-muted-foreground"
                        >
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
                                <th class="px-4 py-3" />
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
                                <td class="px-4 py-3 text-right">
                                    <a
                                        :href="
                                            exportUrl(
                                                version.status === 'draft'
                                                    ? 'draft'
                                                    : version.version,
                                            )
                                        "
                                        class="inline-flex items-center gap-1 text-xs hover:underline"
                                        :title="$t('Export zip')"
                                    >
                                        <Download class="h-3.5 w-3.5" />
                                        zip
                                    </a>
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
        </template>
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
        v-model:open="trialOpen"
        title="Try the draft"
        description="The draft as it was last saved is installed on the test server. Save your changes first."
        submit-label="Try"
        :processing="trialForm.processing"
        @submit="submitTrial"
    >
        <FormField
            label="Test server"
            for="trial_server"
            :error="trialForm.errors.server"
        >
            <NativeSelect
                id="trial_server"
                v-model="trialForm.server"
                :options="serverOptions"
            />
        </FormField>
        <AppInputFields
            v-model="trialForm.inputs"
            :inputs="trialInputs"
            :errors="trialForm.errors"
            id-prefix="trial"
        />
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
