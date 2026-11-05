<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Circle, CircleCheck } from '@lucide/vue';
import { computed } from 'vue';
import { index as billingSystems } from '@/routes/admin/billing-systems';
import { index as clusters } from '@/routes/admin/clusters';
import { index as ipPools } from '@/routes/admin/ip-pools';
import { index as locations } from '@/routes/admin/locations';
import { index as osTemplates } from '@/routes/admin/os-templates';
import { index as packages } from '@/routes/admin/packages';
import type { SetupProgress } from '@/types/admin';

/**
 * The setup order (App\Domain\Installation\SetupProgress), each step linking
 * to its page and ticked once done. Help lists it always; the dashboard
 * until every step is done, with the first open step marked as the next one.
 */
const props = defineProps<{
    setup: SetupProgress;
    /** Mark the first step not done yet as the one to do next. */
    markNext?: boolean;
}>();

const steps = computed(() => [
    {
        done: props.setup.locations,
        href: locations(),
        title: 'Add a location',
        detail: 'A data centre or region. Clusters, IP pools and servers belong to a location.',
    },
    {
        done: props.setup.clusters,
        href: clusters(),
        title: 'Connect a Proxmox cluster or node',
        detail: 'Enter the API host and an API token, then run Check permissions on the cluster page.',
    },
    {
        done: props.setup.ip_pools,
        href: ipPools(),
        title: 'Create an IP pool',
        detail: 'The addresses servers get, with gateway, bridge and network model.',
    },
    {
        done: props.setup.os_templates,
        href: osTemplates(),
        title: 'Add OS templates',
        detail: 'Build a template from an ISO in Proxmox, sync the cluster and add it here.',
    },
    {
        done: props.setup.packages,
        href: packages(),
        title: 'Create packages',
        detail: 'The plans you sell: CPU, memory, disk, addresses and limits.',
    },
    {
        done: props.setup.billing_systems,
        href: billingSystems(),
        title: 'Connect a billing system',
        detail: 'Create an API token for WHMCS or WISECP and install the module there.',
    },
]);

const next = computed(() =>
    props.markNext ? steps.value.findIndex((step) => !step.done) : -1,
);
</script>

<template>
    <ol class="flex flex-col gap-2">
        <li
            v-for="(step, number) in steps"
            :key="step.title"
            class="flex items-start gap-3 rounded-lg border p-3 text-sm"
            :class="{ 'border-foreground/30': number === next }"
            :aria-current="number === next ? 'step' : undefined"
            :data-test="`setup-step-${number + 1}`"
        >
            <CircleCheck
                v-if="step.done"
                class="mt-0.5 h-4 w-4 shrink-0 text-status-success-foreground"
                aria-hidden="true"
            />
            <Circle
                v-else
                class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <span class="sr-only">{{
                step.done ? $t('Done:') : $t('Not done yet:')
            }}</span>
            <div class="flex min-w-0 flex-col gap-0.5">
                <span class="flex flex-wrap items-baseline gap-x-2">
                    <Link :href="step.href" class="font-medium hover:underline">
                        {{ number + 1 }}. {{ $t(step.title) }}
                    </Link>
                    <span
                        v-if="number === next"
                        class="text-xs text-muted-foreground"
                        >{{ $t('Next step') }}</span
                    >
                </span>
                <p class="text-muted-foreground">
                    {{ $t(step.detail) }}
                </p>
            </div>
        </li>
    </ol>
</template>
