<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { PackageCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index as appsIndex } from '@/routes/admin/apps';
import { install as installRoute } from '@/routes/admin/apps/packages';
import type { AppText } from '@/types/admin';

type PackageStatus =
    | 'new'
    | 'update'
    | 'current'
    | 'older'
    | 'edited'
    | 'conflict'
    | 'taken';

/**
 * An uploaded .proxapp package before it is installed (docs/adr/0042): what it is,
 * what installing it would do, and its changes against the installed version.
 */
defineOptions({
    layout: { breadcrumbs: [{ title: 'Apps', href: appsIndex() }] },
});

const props = defineProps<{
    token: string;
    package: {
        slug: string;
        name: string;
        version: string;
        category: string;
        platform: string;
        description: AppText;
        files: string[];
    };
    status: PackageStatus;
    current: string | null;
    diff: string;
}>();

const page = usePage<{ locale: string }>();
const description = computed(
    () =>
        (page.props.locale === 'tr' ? props.package.description.tr : null) ??
        props.package.description.en,
);

const statuses: Record<
    PackageStatus,
    {
        label: string;
        tone: 'success' | 'warning' | 'danger' | 'neutral';
        installable: boolean;
    }
> = {
    new: { label: 'New app', tone: 'success', installable: true },
    update: { label: 'Update', tone: 'success', installable: true },
    current: {
        label: 'Installed already',
        tone: 'neutral',
        installable: false,
    },
    older: { label: 'Older version', tone: 'warning', installable: true },
    edited: {
        label: 'Edited by an administrator',
        tone: 'warning',
        installable: true,
    },
    conflict: {
        label: 'Version in use changed',
        tone: 'danger',
        installable: false,
    },
    taken: {
        label: 'A custom app has this slug',
        tone: 'danger',
        installable: false,
    },
};

const explanations: Record<PackageStatus, string> = {
    new: 'The app is added and offered to customers at once.',
    update: 'The app is updated to this version. Installations keep the version they were installed with.',
    current: 'This version is installed already; there is nothing to do.',
    older: 'This version is older than the installed one. It is stored but not offered.',
    edited: 'An administrator edited this app in place. The version is stored for later and not offered; compare it in the editor.',
    conflict:
        'Installations use this version number with other content. Raise the version in the recipe to change it.',
    taken: 'A custom app already has this slug, so the package cannot be installed.',
};

const installing = ref(false);

const install = () =>
    router.post(
        installRoute(props.token),
        {},
        {
            onStart: () => (installing.value = true),
            onFinish: () => (installing.value = false),
        },
    );

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
    <Head title="Install package" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Install package"
                description="Look at what the package changes before you install it."
            />
            <div class="flex gap-2">
                <Button variant="outline" as-child>
                    <Link :href="appsIndex()">{{ $t('Cancel') }}</Link>
                </Button>
                <Button
                    v-if="statuses[status].installable"
                    :disabled="installing"
                    data-test="install-package"
                    @click="install"
                >
                    <PackageCheck class="h-4 w-4" />
                    {{ $t('Install') }}
                </Button>
            </div>
        </div>

        <div class="rounded-xl border p-4 text-sm">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-base font-medium">{{ package.name }}</span>
                <span class="text-muted-foreground">{{ package.version }}</span>
                <StatusBadge
                    :tone="statuses[status].tone"
                    :label="statuses[status].label"
                    data-test="package-status"
                />
            </div>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ package.slug }} · {{ package.category }} ·
                {{ package.platform }}
            </p>
            <p class="mt-3">{{ description }}</p>
            <p
                class="mt-3 text-muted-foreground"
                data-test="package-explanation"
            >
                {{ $t(explanations[status]) }}
            </p>
            <p v-if="current" class="mt-1 text-xs text-muted-foreground">
                {{ $t('Installed version: :version', { version: current }) }}
            </p>
            <p class="mt-3 text-xs text-muted-foreground">
                {{ $t('Files: :files', { files: package.files.join(', ') }) }}
            </p>
        </div>

        <div class="flex flex-col gap-2">
            <p class="text-sm font-medium">{{ $t('Changes') }}</p>
            <pre
                v-if="diff !== ''"
                class="max-h-[40rem] overflow-auto rounded-lg border bg-muted/30 p-3 font-mono text-xs"
                data-test="package-diff"
            ><template v-for="(line, number) in diff.split('\n')" :key="number"><span :class="lineClass(line)">{{ line }}</span>
</template></pre>
            <p v-else class="text-sm text-muted-foreground">
                {{ $t('No changes.') }}
            </p>
        </div>
    </div>
</template>
