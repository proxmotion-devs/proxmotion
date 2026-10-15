<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { dashboard } from '@/routes/admin';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Admin',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Admin" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
        <Heading
            title="Admin dashboard"
            description="Clusters, nodes, IP pools and servers will be managed here."
        />

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div
                v-for="index in 3"
                :key="index"
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
        </div>
    </div>
</template>
