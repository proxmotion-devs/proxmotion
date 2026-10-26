<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download, RefreshCw } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import GuideBox from '@/components/admin/GuideBox.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as appsIndex, update as updateApp } from '@/routes/admin/apps';
import {
    diff as diffRoute,
    download as downloadRoute,
    index as storeIndex,
    refresh as refreshRoute,
    remove as removeRoute,
} from '@/routes/admin/apps/catalog';

type CatalogStatus =
    | 'not_downloaded'
    | 'current'
    | 'update_available'
    | 'edited'
    | 'taken'
    | 'invalid';

interface StoreSource {
    repository: string;
    ref: string;
    commit: string | null;
    fetchedAt: string | null;
    error: string | null;
}

interface StoreApp {
    id: number;
    slug: string;
    name: string;
    category: string | null;
    platform: string | null;
    version: string | null;
    status: CatalogStatus;
    downloadedVersion: string | null;
    errors: string[];
    app: { slug: string; isActive: boolean; inUse: boolean } | null;
}

/**
 * App Store (docs/adr/0041): the recipes of the official repository. Downloading an
 * app is the administrator's approval; an update shows its changes before it is taken.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Apps', href: appsIndex() },
            { title: 'App Store', href: storeIndex() },
        ],
    },
});

const props = defineProps<{
    source: StoreSource | null;
    apps: StoreApp[];
}>();

const search = ref('');
const categoryFilter = ref('all');
const platformFilter = ref('all');
const refreshing = ref(false);
const diffs = ref<Record<string, string | null>>({});

const statusLabels: Record<
    CatalogStatus,
    { label: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }
> = {
    not_downloaded: { label: 'Not downloaded', tone: 'neutral' },
    current: { label: 'Downloaded', tone: 'success' },
    update_available: { label: 'Update available', tone: 'warning' },
    edited: { label: 'Edited by an administrator', tone: 'warning' },
    taken: { label: 'A custom app has this slug', tone: 'danger' },
    invalid: { label: 'Invalid recipe', tone: 'danger' },
};

const categoryOptions = computed(() => [
    { value: 'all', label: 'All categories' },
    ...[...new Set(props.apps.map((app) => app.category).filter(Boolean))]
        .sort()
        .map((category) => ({
            value: String(category),
            label: String(category),
        })),
]);

const platformOptions = [
    { value: 'all', label: 'All platforms' },
    { value: 'linux', label: 'Linux' },
    { value: 'windows', label: 'Windows' },
];

const filtered = computed(() =>
    props.apps.filter((app) => {
        const term = search.value.trim().toLowerCase();

        return (
            (term === '' ||
                app.name.toLowerCase().includes(term) ||
                app.slug.includes(term)) &&
            (categoryFilter.value === 'all' ||
                app.category === categoryFilter.value) &&
            (platformFilter.value === 'all' ||
                app.platform === platformFilter.value)
        );
    }),
);

const refresh = () => {
    refreshing.value = true;
    router.post(
        refreshRoute(),
        {},
        {
            preserveScroll: true,
            onFinish: () => (refreshing.value = false),
        },
    );
};

const download = (app: StoreApp) =>
    router.post(downloadRoute(app.id), {}, { preserveScroll: true });

const toggleActive = (app: StoreApp) => {
    if (app.app === null) {
        return;
    }

    router.visit(updateApp(app.app.slug), {
        data: { is_active: !app.app.isActive },
        preserveScroll: true,
    });
};

const loadDiff = async (app: StoreApp) => {
    if (app.slug in diffs.value) {
        delete diffs.value[app.slug];

        return;
    }

    const response = await fetch(diffRoute.url(app.id), {
        headers: { Accept: 'application/json' },
    });

    diffs.value[app.slug] = response.ok
        ? ((await response.json()) as { diff: string }).diff
        : null;
};

const lineClass = (line: string) => {
    if (line.startsWith('+')) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (line.startsWith('-')) {
        return 'text-red-600 dark:text-red-400';
    }

    return line.startsWith('@@') ? 'text-sky-600 dark:text-sky-400' : '';
};
</script>

<template>
    <Head title="App Store" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="App Store"
                description="Apps for your servers, from the official recipe repository. Download an app to offer it; an update is shown with its changes before you take it."
            />
            <Button
                variant="outline"
                data-test="refresh-store"
                :disabled="refreshing"
                @click="refresh"
            >
                <RefreshCw class="h-4 w-4" />
                {{ $t('Refresh') }}
            </Button>
        </div>

        <GuideBox
            id="app-store"
            title="How the App Store works"
            topic="apps-bundled"
        >
            <ol>
                <li>
                    {{
                        $t(
                            'The recipes are read every six hours, or when you refresh. Nothing is installed from them until you download an app.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Downloading offers an app to customers. Updating keeps the version your servers were installed with; look at the changes first.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'An app with installations cannot be removed; deactivate it instead.',
                        )
                    }}
                </li>
            </ol>
        </GuideBox>

        <div
            v-if="source === null"
            class="rounded-xl border p-6 text-sm text-muted-foreground"
            data-test="store-not-read"
        >
            {{
                $t('The recipes have not been read yet. Refresh to read them.')
            }}
        </div>

        <template v-else>
            <div class="rounded-xl border p-4 text-sm">
                <p class="font-medium">{{ $t('Recipe source') }}</p>
                <p
                    class="mt-1 font-mono text-xs break-all text-muted-foreground"
                >
                    {{ source.repository }} · {{ source.ref }}
                    <template v-if="source.commit">
                        · {{ source.commit.slice(0, 12) }}</template
                    >
                </p>
                <p
                    v-if="source.fetchedAt"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    {{
                        $t('Last read: :time', {
                            time: new Date(source.fetchedAt).toLocaleString(),
                        })
                    }}
                </p>
                <p
                    v-if="source.error"
                    class="mt-2 text-sm break-words text-destructive"
                    data-test="store-error"
                >
                    {{ source.error }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Input
                    v-model="search"
                    :placeholder="$t('Search apps')"
                    class="w-64"
                    data-test="store-search"
                />
                <NativeSelect
                    v-model="categoryFilter"
                    :options="categoryOptions"
                    :aria-label="$t('Category')"
                    class="w-auto"
                />
                <NativeSelect
                    v-model="platformFilter"
                    :options="platformOptions"
                    :aria-label="$t('Platform')"
                    class="w-auto"
                />
            </div>

            <div class="grid gap-3">
                <div
                    v-for="app in filtered"
                    :key="app.slug"
                    class="rounded-xl border p-4"
                    data-test="store-app"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ app.name }}
                                <span
                                    class="ml-1 text-xs font-normal text-muted-foreground"
                                    >{{ app.version ?? '' }}</span
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ app.slug }}
                                <template v-if="app.category">
                                    · {{ app.category }}</template
                                >
                                <template v-if="app.platform">
                                    · {{ app.platform }}</template
                                >
                            </p>
                            <p
                                v-if="
                                    app.status === 'update_available' &&
                                    app.downloadedVersion
                                "
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{
                                    $t('Downloaded: :version', {
                                        version: app.downloadedVersion,
                                    })
                                }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <StatusBadge
                                :tone="statusLabels[app.status].tone"
                                :label="statusLabels[app.status].label"
                                data-test="store-status"
                            />
                            <Button
                                v-if="app.status === 'not_downloaded'"
                                size="sm"
                                data-test="store-download"
                                @click="download(app)"
                            >
                                <Download class="h-4 w-4" />
                                {{ $t('Download') }}
                            </Button>
                            <Button
                                v-if="app.status === 'update_available'"
                                size="sm"
                                data-test="store-update"
                                @click="download(app)"
                            >
                                <Download class="h-4 w-4" />
                                {{ $t('Update') }}
                            </Button>
                            <Button
                                v-if="
                                    app.status === 'update_available' ||
                                    app.status === 'edited'
                                "
                                variant="outline"
                                size="sm"
                                data-test="store-changes"
                                @click="loadDiff(app)"
                            >
                                {{ $t('Changes') }}
                            </Button>
                            <Button
                                v-if="app.app !== null"
                                variant="outline"
                                size="sm"
                                data-test="store-toggle"
                                @click="toggleActive(app)"
                            >
                                {{
                                    app.app.isActive
                                        ? $t('Deactivate')
                                        : $t('Activate')
                                }}
                            </Button>
                            <ConfirmDelete
                                v-if="app.app !== null && !app.app.inUse"
                                :action="removeRoute(app.app.slug)"
                                :name="app.name"
                                :description="
                                    $t(
                                        'The app is removed from the panel. Its recipe stays in the repository and can be downloaded again.',
                                    )
                                "
                                with-label
                            />
                        </div>
                    </div>

                    <ul
                        v-if="app.errors.length"
                        class="mt-3 list-disc pl-5 text-xs text-destructive"
                    >
                        <li v-for="error in app.errors" :key="error">
                            {{ error }}
                        </li>
                    </ul>

                    <pre
                        v-if="typeof diffs[app.slug] === 'string'"
                        class="mt-3 max-h-[32rem] overflow-auto rounded-lg border bg-muted/30 p-3 font-mono text-xs"
                        data-test="store-changes-text"
                    ><template v-for="(line, number) in (diffs[app.slug] ?? '').split('\n')" :key="number"><span :class="lineClass(line)">{{ line }}</span>
</template></pre>
                </div>

                <p
                    v-if="filtered.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ $t('No apps match the filters.') }}
                </p>
            </div>
        </template>
    </div>
</template>
