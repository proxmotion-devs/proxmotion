<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Disc3,
    MapPin,
    Network,
    Package,
    Server,
    TriangleAlert,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import GuideBox from '@/components/admin/GuideBox.vue';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes/admin';
import { index as clusters } from '@/routes/admin/clusters';
import { index as drifts } from '@/routes/admin/drifts';
import { index as ipPools } from '@/routes/admin/ip-pools';
import { index as locations } from '@/routes/admin/locations';
import { index as osTemplates } from '@/routes/admin/os-templates';
import { index as packages } from '@/routes/admin/packages';
import type { RouteDefinition } from '@/wayfinder';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: dashboard() }] },
});

const props = defineProps<{
    stats: {
        locations: number;
        clusters: number;
        clusters_failing: number;
        nodes: number;
        nodes_online: number;
        ip_addresses: number;
        ip_addresses_reserved: number;
        os_templates: number;
        packages: number;
        drifts: number;
    };
}>();

type Card = {
    title: string;
    value: string;
    detail?: string;
    warning?: boolean;
    icon: Component;
    href: RouteDefinition<'get'>;
};

const cards = computed<Card[]>(() => [
    {
        title: 'Drift report',
        value: String(props.stats.drifts),
        detail: props.stats.drifts
            ? 'Proxmox differs from the panel'
            : undefined,
        warning: props.stats.drifts > 0,
        icon: TriangleAlert,
        href: drifts(),
    },
    {
        title: 'Locations',
        value: String(props.stats.locations),
        icon: MapPin,
        href: locations(),
    },
    {
        title: 'Clusters',
        value: String(props.stats.clusters),
        detail: props.stats.clusters_failing
            ? 'Some clusters failed to sync'
            : undefined,
        warning: props.stats.clusters_failing > 0,
        icon: Server,
        href: clusters(),
    },
    {
        title: 'Nodes online',
        value: `${props.stats.nodes_online} / ${props.stats.nodes}`,
        warning: props.stats.nodes_online < props.stats.nodes,
        icon: Server,
        href: clusters(),
    },
    {
        title: 'IP addresses',
        value: String(props.stats.ip_addresses),
        icon: Network,
        href: ipPools(),
    },
    {
        title: 'Active OS templates',
        value: String(props.stats.os_templates),
        icon: Disc3,
        href: osTemplates(),
    },
    {
        title: 'Active packages',
        value: String(props.stats.packages),
        icon: Package,
        href: packages(),
    },
]);
</script>

<template>
    <Head title="Admin" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Admin dashboard"
            description="Overview of the infrastructure connected to the panel."
        />

        <GuideBox
            id="dashboard"
            title="New to Proxmotion?"
            topic="getting-started"
        >
            <p>
                {{
                    $t(
                        'Set the panel up in this order: location, Proxmox cluster, IP pool, OS templates, packages, billing system. Help shows which steps are done and explains each page.',
                    )
                }}
            </p>
        </GuideBox>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="card in cards"
                :key="card.title"
                :href="card.href"
                class="flex flex-col gap-2 rounded-xl border p-5 transition-colors hover:bg-muted/40"
                :class="{
                    'border-amber-300 dark:border-amber-800': card.warning,
                }"
            >
                <div
                    class="flex items-center justify-between text-sm text-muted-foreground"
                >
                    {{ $t(card.title) }}
                    <component :is="card.icon" class="h-4 w-4" />
                </div>
                <div class="text-3xl font-semibold tabular-nums">
                    {{ card.value }}
                </div>
                <div
                    v-if="card.detail"
                    class="text-xs text-amber-700 dark:text-amber-400"
                >
                    {{ $t(card.detail) }}
                </div>
            </Link>
        </div>
    </div>
</template>
