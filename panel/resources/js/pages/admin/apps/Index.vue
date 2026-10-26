<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { FileUp, Package, Pencil, Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import AppSystemsDialog from '@/components/admin/AppSystemsDialog.vue';
import type { AppOsVersion } from '@/components/admin/AppSystemsPicker.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import GuideBox from '@/components/admin/GuideBox.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    matchesFilters,
    osFilterOptions,
    type OsFilter,
    type PlatformFilter,
} from '@/lib/appFilters';
import {
    edit,
    importMethod as importRoute,
    index,
    store,
    update,
} from '@/routes/admin/apps';
import {
    remove as removePackage,
    store as storePackage,
} from '@/routes/admin/apps/packages';
import type { AppText } from '@/types/admin';

/**
 * Apps: installed from .proxapp packages (docs/adr/0042)
 * (docs/adr/0015) and custom apps written in the editor (docs/adr/0018).
 * Inactive apps are neither listed nor installable.
 */
defineOptions({
    layout: { breadcrumbs: [{ title: 'Apps', href: index() }] },
});

const props = defineProps<{
    osVersions: AppOsVersion[];
    apps: {
        slug: string;
        name: string;
        source: 'bundled' | 'custom';
        /** A bundled app an administrator edited in place. */
        modified: boolean;
        published: boolean;
        draft_version: string | null;
        version: string | null;
        category: string;
        description: AppText;
        platform: string;
        recipe_os: string[];
        suggested_ids: number[];
        os_version_ids: number[];
        billing: 'free' | 'paid';
        is_active: boolean;
        installed_count: number;
        /** Installations use it: it can be deactivated, not removed. */
        in_use: boolean;
    }[];
}>();

const page = usePage<{ locale: string }>();

const describe = (text: AppText) =>
    (page.props.locale === 'tr' ? text.tr : null) ?? text.en;

// The operating system versions an app is offered on (docs/adr/0026).
// The app whose systems are open, by slug: the props refresh after each tick.
const systemsSlug = ref<string | null>(null);
const systemsFor = computed(
    () => props.apps.find((app) => app.slug === systemsSlug.value) ?? null,
);
const systemsOpen = computed({
    get: () => systemsFor.value !== null,
    set: (value: boolean) => {
        if (!value) {
            systemsSlug.value = null;
        }
    },
});

/** "Ubuntu 22.04, 24.04 · Debian 12". */
const tickedLabel = (ids: number[]) => {
    const bySystem = new Map<string, string[]>();

    for (const version of props.osVersions) {
        if (ids.includes(version.id)) {
            bySystem.set(version.system, [
                ...(bySystem.get(version.system) ?? []),
                version.version,
            ]);
        }
    }

    return [...bySystem.entries()]
        .map(([system, versions]) => `${system} ${versions.join(', ')}`)
        .join(' · ');
};

// Filters by platform and by the systems an app is ticked for.
const platformFilter = ref<PlatformFilter>('all');
const osFilter = ref<OsFilter>('all');

const platformFilterOptions = computed(() => [
    { value: 'all' as const, label: trans('All platforms') },
    { value: 'linux' as const, label: trans('Linux') },
    { value: 'windows' as const, label: trans('Windows') },
]);

const osOptions = computed(() => [
    { value: 'all', label: trans('All operating systems') },
    { value: 'none', label: trans('Not offered on any system') },
    ...osFilterOptions(
        props.osVersions,
        platformFilter.value,
        trans('All versions'),
    ),
]);

// A system of the other platform is no longer listed.
watch(platformFilter, () => {
    if (!osOptions.value.some((option) => option.value === osFilter.value)) {
        osFilter.value = 'all';
    }
});

const filteredApps = computed(() =>
    props.apps.filter((app) =>
        matchesFilters(
            app,
            platformFilter.value,
            osFilter.value,
            props.osVersions,
        ),
    ),
);

const filtered = computed(
    () => platformFilter.value !== 'all' || osFilter.value !== 'all',
);

const clearFilters = () => {
    platformFilter.value = 'all';
    osFilter.value = 'all';
};

const toggle = (slug: string, active: boolean) =>
    router.visit(update(slug), {
        data: { is_active: active },
        preserveScroll: true,
    });

const createOpen = ref(false);
const createForm = useForm({ slug: '', name: '', platform: 'linux' });
// Windows apps run PowerShell scripts (docs/adr/0029).
const platformOptions = [
    { value: 'linux', label: 'Linux' },
    { value: 'windows', label: 'Windows' },
];

// Import a recipe zip as a new custom app (docs/adr/0018 §5); the panel opens its editor.
const importOpen = ref(false);
const importForm = useForm<{
    archive: File | null;
    slug: string;
    name: string;
}>({ archive: null, slug: '', name: '' });

const openImport = () => {
    importForm.reset();
    importForm.clearErrors();
    importOpen.value = true;
};

const pickArchive = (event: Event) => {
    importForm.archive =
        (event.target as HTMLInputElement).files?.item(0) ?? null;
};

const submitImport = () =>
    importForm.submit(importRoute(), {
        onSuccess: () => (importOpen.value = false),
    });

// Install an app from a .proxapp package (docs/adr/0042); the next page shows what it changes.
const packageOpen = ref(false);
const packageForm = useForm<{ package: File | null }>({ package: null });

const openPackage = () => {
    packageForm.reset();
    packageForm.clearErrors();
    packageOpen.value = true;
};

const pickPackage = (event: Event) => {
    packageForm.package =
        (event.target as HTMLInputElement).files?.item(0) ?? null;
};

const submitPackage = () =>
    packageForm.submit(storePackage(), {
        onSuccess: () => (packageOpen.value = false),
    });

const submitCreate = () =>
    createForm.submit(store(), {
        onSuccess: () => {
            const slug = createForm.slug;
            createOpen.value = false;
            router.visit(edit(slug));
        },
    });
</script>

<template>
    <Head title="Apps" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Apps"
                description="Apps customers can install on their servers through the billing system. Install them from .proxapp packages; edit an installed app here (later packages keep your changes), or write your own."
            />
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    data-test="install-package"
                    @click="openPackage"
                >
                    <Package class="h-4 w-4" />
                    {{ $t('Install package') }}
                </Button>
                <Button
                    variant="outline"
                    data-test="import-app"
                    @click="openImport"
                >
                    <FileUp class="h-4 w-4" />
                    {{ $t('Import app') }}
                </Button>
                <Button
                    data-test="new-app"
                    @click="
                        createForm.reset();
                        createForm.clearErrors();
                        createOpen = true;
                    "
                >
                    <Plus class="h-4 w-4" />
                    {{ $t('New app') }}
                </Button>
            </div>
        </div>

        <GuideBox id="apps" title="How to add an app" topic="apps-own">
            <ol>
                <li>
                    {{
                        $t(
                            'Install apps from .proxapp packages (the recipes repository has one for each app), or write your own. Deactivate the ones you do not want to offer.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'New app creates a draft from a working skeleton (Import app takes a recipe zip instead). Fill in the tabs, write install.sh and save the draft.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Try the draft on a test server (Trial runs tab), then publish it. Deactivate it while something is wrong.',
                        )
                    }}
                </li>
            </ol>
        </GuideBox>

        <div v-if="apps.length" class="flex flex-wrap items-center gap-2">
            <NativeSelect
                v-model="platformFilter"
                :options="platformFilterOptions"
                :aria-label="$t('Platform')"
                class="w-auto"
                data-test="platform-filter"
            />
            <NativeSelect
                v-model="osFilter"
                :options="osOptions"
                :aria-label="$t('Operating system')"
                class="w-auto"
                data-test="os-filter"
            />
            <Button
                v-if="filtered"
                variant="ghost"
                size="sm"
                data-test="clear-filters"
                @click="clearFilters"
            >
                {{ $t('Clear filters') }}
            </Button>
            <span
                v-if="filtered"
                class="text-xs text-muted-foreground"
                data-test="filter-count"
            >
                {{
                    $t(':shown of :total apps', {
                        shown: String(filteredApps.length),
                        total: String(apps.length),
                    })
                }}
            </span>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('App') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Operating systems') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Installed') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="app in filteredApps"
                        :key="app.slug"
                        class="border-t"
                    >
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ app.name }}</span>
                            <span class="ml-1 text-xs text-muted-foreground">{{
                                app.version ?? ''
                            }}</span>
                            <StatusBadge
                                v-if="app.source === 'custom'"
                                class="ml-2"
                                tone="neutral"
                                label="Custom"
                            />
                            <StatusBadge
                                v-if="app.modified"
                                class="ml-2"
                                tone="warning"
                                label="Modified"
                            />
                            <StatusBadge
                                v-if="app.draft_version"
                                class="ml-2"
                                tone="warning"
                                label="Draft"
                            />
                            <StatusBadge
                                v-if="!app.is_active"
                                tone="neutral"
                                label="Inactive"
                                class="ml-2"
                            />
                            <StatusBadge
                                v-if="app.billing === 'paid'"
                                tone="warning"
                                label="Paid"
                                class="ml-2"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ describe(app.description) }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <span v-if="app.os_version_ids.length">{{
                                tickedLabel(app.os_version_ids)
                            }}</span>
                            <StatusBadge
                                v-else
                                tone="warning"
                                label="Not offered on any system"
                            />
                            <Button
                                variant="link"
                                size="sm"
                                class="block h-auto px-0 text-xs"
                                :data-test="`systems-${app.slug}`"
                                @click="systemsSlug = app.slug"
                            >
                                {{ $t('Choose systems') }}
                            </Button>
                        </td>
                        <td class="px-4 py-3">{{ app.installed_count }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <Button
                                as-child
                                variant="outline"
                                size="sm"
                                class="mr-2"
                            >
                                <Link :href="edit(app.slug)">
                                    <Pencil class="h-4 w-4" />
                                    {{ $t('Edit') }}
                                </Link>
                            </Button>
                            <Button
                                v-if="app.published"
                                variant="outline"
                                size="sm"
                                @click="toggle(app.slug, !app.is_active)"
                            >
                                {{
                                    app.is_active
                                        ? $t('Deactivate')
                                        : $t('Activate')
                                }}
                            </Button>
                            <ConfirmDelete
                                v-if="app.source === 'bundled' && !app.in_use"
                                :action="removePackage(app.slug)"
                                :name="app.name"
                                description="The app and its versions are removed from the panel. Install its package again to bring it back."
                            />
                        </td>
                    </tr>
                    <tr v-if="apps.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{
                                $t(
                                    'No apps yet. Install them from .proxapp packages.',
                                )
                            }}
                        </td>
                    </tr>
                    <tr v-else-if="filteredApps.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                            data-test="no-filtered-apps"
                        >
                            {{ $t('No apps match the filters.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <AppSystemsDialog
        v-if="systemsFor"
        v-model:open="systemsOpen"
        :app="systemsFor"
        :os-versions="osVersions"
    />

    <FormDialog
        v-model:open="createOpen"
        title="New app"
        description="A custom app starts as a draft made from the recipe skeleton. It is offered to customers once you publish it."
        submit-label="Create"
        :processing="createForm.processing"
        @submit="submitCreate"
    >
        <FormField
            label="Slug"
            for="new_slug"
            hint="Lower-case letters, digits and dashes, e.g. uptime-kuma. It cannot be changed later."
            :error="createForm.errors.slug"
        >
            <Input id="new_slug" v-model="createForm.slug" class="font-mono" />
        </FormField>
        <FormField label="Name" for="new_name" :error="createForm.errors.name">
            <Input id="new_name" v-model="createForm.name" />
        </FormField>
        <FormField
            label="Platform"
            for="new_platform"
            hint="Linux apps run shell scripts, Windows apps PowerShell scripts."
            :error="createForm.errors.platform"
        >
            <NativeSelect
                id="new_platform"
                v-model="createForm.platform"
                :options="platformOptions"
            />
        </FormField>
    </FormDialog>

    <FormDialog
        v-model:open="importOpen"
        title="Import an app"
        description="A .proxapp package or a zip of a recipe directory, as exported from a panel. It becomes a new custom app as a draft that you can change."
        submit-label="Import"
        :processing="importForm.processing"
        @submit="submitImport"
    >
        <FormField
            label="Recipe zip"
            for="import_archive"
            :error="importForm.errors.archive"
        >
            <input
                id="import_archive"
                type="file"
                accept=".proxapp,.zip,application/zip"
                class="text-sm"
                data-test="import-archive"
                @change="pickArchive"
            />
        </FormField>
        <FormField
            label="Slug"
            for="import_slug"
            hint="Leave empty to use the recipe's own slug."
            :error="importForm.errors.slug"
        >
            <Input
                id="import_slug"
                v-model="importForm.slug"
                class="font-mono"
            />
        </FormField>
        <FormField
            label="Name"
            for="import_name"
            hint="Leave empty to use the recipe's own name."
            :error="importForm.errors.name"
        >
            <Input id="import_name" v-model="importForm.name" />
        </FormField>
    </FormDialog>

    <FormDialog
        v-model:open="packageOpen"
        title="Install package"
        description="A .proxapp package of an app. The next page shows what it changes before anything is installed."
        submit-label="Continue"
        :processing="packageForm.processing"
        @submit="submitPackage"
    >
        <FormField
            label="Package"
            for="package_file"
            :error="packageForm.errors.package"
        >
            <input
                id="package_file"
                type="file"
                accept=".proxapp"
                class="text-sm"
                data-test="package-file"
                @change="pickPackage"
            />
        </FormField>
    </FormDialog>
</template>
