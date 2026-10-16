<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Edit the reverse DNS name (PTR) of one address. Empty removes it.
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    address: string;
    ptr: string | null;
    disabled?: boolean;
}>();

const open = ref(false);
const form = useForm({ address: props.address, hostname: '' });

const openDialog = () => {
    form.clearErrors();
    form.defaults({ address: props.address, hostname: props.ptr ?? '' });
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
        variant="ghost"
        size="icon"
        class="h-6 w-6"
        :disabled="disabled"
        :aria-label="$t('Edit reverse DNS of :address', { address })"
        :title="$t('Edit reverse DNS of :address', { address })"
        @click="openDialog"
    >
        <Pencil class="h-3 w-3" />
    </Button>

    <FormDialog
        v-model:open="open"
        title="Reverse DNS"
        description="The hostname this address points back to (PTR record), e.g. mail.example.com. Leave empty to remove it."
        :processing="form.processing"
        @submit="submit"
    >
        <p class="font-mono text-sm">{{ address }}</p>
        <FormField
            label="Hostname"
            for="hostname"
            :error="form.errors.hostname || form.errors.address"
        >
            <Input
                id="hostname"
                v-model="form.hostname"
                class="font-mono"
                placeholder="mail.example.com"
                autocomplete="off"
            />
        </FormField>
    </FormDialog>
</template>
