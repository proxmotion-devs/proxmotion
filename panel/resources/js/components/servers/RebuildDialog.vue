<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Hammer } from '@lucide/vue';
import { ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Rebuild a failed creation from scratch (docs/adr/0032 §7): the half-built
 * VM and its disks are deleted, then the server is created again with the
 * same VMID, addresses and MAC address. The hostname is typed as confirmation.
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    hostname: string;
    disabled?: boolean;
}>();

const open = ref(false);
const form = useForm({ confirm_hostname: '' });

const openDialog = () => {
    form.clearErrors();
    form.reset();
    open.value = true;
};

const submit = () =>
    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
</script>

<template>
    <Button
        variant="outline"
        size="sm"
        :disabled="disabled"
        data-test="server-rebuild"
        @click="openDialog"
    >
        <Hammer class="h-4 w-4" />
        {{ $t('Rebuild from scratch') }}
    </Button>

    <FormDialog
        v-model:open="open"
        title="Rebuild from scratch?"
        description="The VM this creation left behind and its disks are deleted, then the server is created again from its OS template with the same VMID, IP addresses and MAC address. Use it when Retry keeps failing on a broken VM. A VM Proxmotion did not create for this server is never deleted."
        submit-label="Rebuild"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField
            label="Type the hostname to confirm"
            for="rebuild_confirm_hostname"
            :error="form.errors.confirm_hostname"
        >
            <Input
                id="rebuild_confirm_hostname"
                v-model="form.confirm_hostname"
                class="font-mono"
                autocomplete="off"
                required
                :placeholder="hostname"
            />
        </FormField>
    </FormDialog>
</template>
