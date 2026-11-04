<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, useId, watch } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import HostnameConfirmField from '@/components/admin/HostnameConfirmField.vue';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Running a script's draft on a server that is not a test server: the draft
 * has never run on customers' servers, so its hostname is typed again (the
 * server checks `confirm_hostname` too). Test servers run it without asking.
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    hostname: string;
    /** What the run sends besides the confirmation (server, draft, trace). */
    data: Record<string, unknown>;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ started: [] }>();

const form = useForm({ confirm_hostname: '' });
const inputId = `draft-run-hostname-${useId()}`;

const matches = computed(
    () =>
        form.confirm_hostname.trim().toLowerCase() ===
        props.hostname.toLowerCase(),
);

watch(open, (value) => {
    if (value) {
        form.clearErrors();
        form.reset();
    }
});

const submit = () => {
    if (!matches.value) {
        return;
    }

    form.transform((values) => ({ ...props.data, ...values })).submit(
        props.action,
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                emit('started');
            },
        },
    );
};
</script>

<template>
    <FormDialog
        v-model:open="open"
        title="Run the draft on this server?"
        description="This is not a test server. The draft has not been published yet and runs on it as root, or as SYSTEM on Windows. Try drafts on a test server first."
        submit-label="Run draft"
        :processing="form.processing"
        :submit-disabled="!matches"
        destructive
        @submit="submit"
    >
        <HostnameConfirmField
            :id="inputId"
            v-model="form.confirm_hostname"
            :hostname="hostname"
            :error="form.errors.confirm_hostname"
        />
    </FormDialog>
</template>
