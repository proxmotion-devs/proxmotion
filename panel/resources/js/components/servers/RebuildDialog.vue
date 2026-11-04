<script setup lang="ts">
import { Hammer } from '@lucide/vue';
import TypedConfirmDialog from '@/components/admin/TypedConfirmDialog.vue';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Rebuild a failed creation from scratch (docs/adr/0032 §7): the half-built
 * VM and its disks are deleted, then the server is created again with the
 * same VMID, addresses and MAC address. The hostname is typed as confirmation.
 */
defineProps<{
    action: RouteDefinition<'post'>;
    hostname: string;
    disabled?: boolean;
}>();
</script>

<template>
    <TypedConfirmDialog
        :action="action"
        :hostname="hostname"
        :disabled="disabled"
        :icon="Hammer"
        label="Rebuild from scratch"
        title="Rebuild from scratch?"
        description="The VM this creation left behind and its disks are deleted, then the server is created again from its OS template with the same VMID, IP addresses and MAC address. Use it when Retry keeps failing on a broken VM. A VM Proxmotion did not create for this server is never deleted."
        submit-label="Rebuild"
        destructive
        data-test="server-rebuild"
    />
</template>
