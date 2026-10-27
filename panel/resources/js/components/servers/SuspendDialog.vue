<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Pause } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Suspend a server with an optional reason, shown on its page until it is
 * unsuspended. A server the panel suspended itself (docs/adr/0039) stays
 * suspended, and the suspension becomes the administrator's.
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    takeOver?: boolean;
    disabled?: boolean;
}>();

const open = ref(false);
const form = useForm({ reason: '' });

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

const description = computed(() =>
    props.takeOver
        ? 'The panel suspended this server itself. It then stays suspended until you or the billing system unsuspend it.'
        : 'The server is shut down and stays off until it is unsuspended. Its disks and IP addresses are kept.',
);
</script>

<template>
    <Button
        variant="outline"
        size="sm"
        :disabled="disabled"
        data-test="suspend"
        @click="openDialog"
    >
        <Pause class="h-4 w-4" />
        {{ takeOver ? $t('Keep suspended') : $t('Suspend') }}
    </Button>

    <FormDialog
        v-model:open="open"
        :title="takeOver ? 'Keep the server suspended' : 'Suspend the server'"
        :description="description"
        :submit-label="takeOver ? 'Keep suspended' : 'Suspend'"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField
            label="Reason (optional)"
            for="suspension_reason"
            hint="Shown on the server page until it is unsuspended."
            :error="form.errors.reason"
        >
            <Input
                id="suspension_reason"
                v-model="form.reason"
                maxlength="255"
                autocomplete="off"
            />
        </FormField>
    </FormDialog>
</template>
