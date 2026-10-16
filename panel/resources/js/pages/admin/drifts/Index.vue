<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DriftList from '@/components/admin/DriftList.vue';
import Heading from '@/components/Heading.vue';
import { index } from '@/routes/admin/drifts';
import type { Drift, Paginated } from '@/types/admin';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Drift report', href: index() }] },
});

defineProps<{ drifts: Paginated<Drift> }>();
</script>

<template>
    <Head title="Drift report" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Drift report"
            description="Differences between the panel and Proxmox, found by the cluster sync. Nothing is changed automatically."
        />

        <DriftList v-if="drifts.total > 0" :drifts="drifts.data" with-server />
        <p
            v-else
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            {{ $t('Proxmox matches the panel.') }}
        </p>

        <nav v-if="drifts.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in drifts.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="{
                        'bg-primary text-primary-foreground': link.active,
                    }"
                >
                    <span v-html="link.label" />
                </Link>
            </template>
        </nav>
    </div>
</template>
