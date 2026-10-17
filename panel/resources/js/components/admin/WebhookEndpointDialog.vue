<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { WebhookEndpoint } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

const props = defineProps<{
    /** Wayfinder route the form is sent to (store or update). */
    action: RouteDefinition<'post'> | RouteDefinition<'put'>;
    events: { value: string; label: string }[];
    /** The endpoint being edited; null to add one. */
    endpoint: WebhookEndpoint | null;
    description?: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    url: '',
    events: [] as string[],
    active: true,
});

// Fill the form each time the dialog opens.
watch(
    open,
    (value) => {
        if (!value) {
            return;
        }

        form.clearErrors();
        form.defaults({
            url: props.endpoint?.url ?? '',
            // A new endpoint usually wants everything; narrow it down if needed.
            events: props.endpoint?.events ?? props.events.map((e) => e.value),
            active: props.endpoint?.active ?? true,
        });
        form.reset();
    },
    { immediate: true },
);

const toggleEvent = (event: string, checked: boolean | 'indeterminate') => {
    form.events =
        checked === true
            ? [...new Set([...form.events, event])]
            : form.events.filter((value) => value !== event);
};

const submit = () => {
    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
};
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="endpoint ? 'Edit webhook' : 'Add webhook'"
        :description="description"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField
            label="URL"
            for="webhook_url"
            :error="form.errors.url"
            hint="The billing system's address that receives the events, e.g. https://billing.example.com/proxmotion-webhook.php."
        >
            <Input
                id="webhook_url"
                v-model="form.url"
                type="url"
                required
                autocomplete="off"
                placeholder="https://billing.example.com/proxmotion-webhook.php"
            />
        </FormField>

        <FormField label="Events" :error="form.errors.events">
            <div class="flex flex-col gap-2">
                <label
                    v-for="event in events"
                    :key="event.value"
                    class="flex items-start gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="form.events.includes(event.value)"
                        @update:model-value="
                            (checked) => toggleEvent(event.value, checked)
                        "
                    />
                    <span>
                        {{ $t(event.label) }}
                        <span
                            class="block font-mono text-xs text-muted-foreground"
                            >{{ event.value }}</span
                        >
                    </span>
                </label>
            </div>
        </FormField>

        <label v-if="endpoint" class="flex items-center gap-2 text-sm">
            <Checkbox
                :model-value="form.active"
                @update:model-value="
                    (checked) => (form.active = checked === true)
                "
            />
            {{ $t('Active') }}
        </label>
    </FormDialog>
</template>
