<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DriftList from '@/components/admin/DriftList.vue';
import Pagination from '@/components/admin/Pagination.vue';
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

        <Pagination :paginator="drifts" />
    </div>
</template>
