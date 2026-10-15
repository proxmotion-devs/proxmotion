<script setup lang="ts">
import StatusBadge from '@/components/admin/StatusBadge.vue';
import type { ServerStatus } from '@/types/admin';

defineProps<{ status: ServerStatus }>();

const tones: Record<
    ServerStatus,
    'success' | 'warning' | 'danger' | 'neutral'
> = {
    pending: 'neutral',
    provisioning: 'warning',
    active: 'success',
    suspended: 'warning',
    terminating: 'warning',
    terminated: 'neutral',
    error: 'danger',
};

// English keys, translated by StatusBadge.
const labels: Record<ServerStatus, string> = {
    pending: 'Pending',
    provisioning: 'Provisioning',
    active: 'Active',
    suspended: 'Suspended',
    terminating: 'Deleting',
    terminated: 'Deleted',
    error: 'Error',
};
</script>

<template>
    <StatusBadge :tone="tones[status]" :label="labels[status]" />
</template>
