<script setup lang="ts">
import { computed } from 'vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';

/**
 * What Proxmox reports about an active server's power, worded apart from the
 * panel's own status ("Powered on" next to "Active"), so the two read as one
 * state line. A polite status region: polling that brings a new state is
 * read out.
 */
const props = defineProps<{
    /** Proxmox power state; undefined while loading, null when unknown. */
    power: string | null | undefined;
}>();

const label = computed(() => {
    if (props.power === undefined) {
        return 'Checking…';
    }

    if (props.power === 'running') {
        return 'Powered on';
    }

    return props.power === 'stopped' ? 'Powered off' : 'Power state unknown';
});
</script>

<template>
    <span role="status" aria-live="polite" data-test="power-state">
        <StatusBadge
            :tone="power === 'running' ? 'success' : 'neutral'"
            :label="label"
        />
    </span>
</template>
