<script setup lang="ts">
import StatusBadge from '@/components/admin/StatusBadge.vue';
import type { ServerStatus, StatusTone } from '@/types/admin';

defineProps<{ status: ServerStatus }>();

const tones: Record<ServerStatus, StatusTone> = {
    pending: 'neutral',
    provisioning: 'progress',
    active: 'success',
    suspended: 'warning',
    reinstalling: 'progress',
    terminating: 'progress',
    terminated: 'neutral',
    error: 'danger',
};

// English keys, translated by StatusBadge.
const labels: Record<ServerStatus, string> = {
    pending: 'Pending',
    provisioning: 'Provisioning',
    active: 'Active',
    suspended: 'Suspended',
    reinstalling: 'Reinstalling',
    terminating: 'Deleting',
    terminated: 'Deleted',
    error: 'Error',
};
</script>

<template>
    <StatusBadge :tone="tones[status]" :label="labels[status]" />
</template>
