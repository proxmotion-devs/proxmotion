<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Play } from '@lucide/vue';
import { ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import { Button } from '@/components/ui/button';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Unsuspend a server after a confirmation: it is started again and becomes
 * active once its guest agent answers.
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    disabled?: boolean;
}>();

const open = ref(false);
const form = useForm({});

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
        data-test="unsuspend"
        @click="open = true"
    >
        <Play class="h-4 w-4" />
        {{ $t('Unsuspend') }}
    </Button>

    <FormDialog
        v-model:open="open"
        title="Unsuspend the server"
        description="The server is started again and its suspension reason is cleared. The billing system is told through its webhooks, if it has any."
        submit-label="Unsuspend"
        :processing="form.processing"
        @submit="submit"
    />
</template>
