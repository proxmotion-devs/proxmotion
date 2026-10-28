<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { edit, update } from '@/routes/notifications';

/**
 * The events this administrator gets email about (docs/adr/0044). Mail is
 * only sent while notifications are on under Panel settings.
 */
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notification settings', href: edit() }],
    },
});

const props = defineProps<{
    events: { value: string; label: string }[];
    selected: string[];
    enabled: boolean;
    verified: boolean;
}>();

const form = useForm({ events: [...props.selected] });

const toggle = (value: string, checked: boolean) => {
    form.events = checked
        ? [...form.events, value]
        : form.events.filter((event) => event !== value);
};

const submit = () => form.submit(update(), { preserveScroll: true });
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">{{ $t('Notification settings') }}</h1>

    <form class="space-y-6" @submit.prevent="submit">
        <Heading
            variant="small"
            title="Email notifications"
            description="Choose what the panel emails you about. Mail is written in your language."
        />

        <p
            v-if="!enabled"
            class="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
            data-test="notifications-off"
        >
            {{
                $t(
                    'Notifications are turned off for the whole panel. An administrator can turn them on under Panel settings.',
                )
            }}
        </p>
        <p
            v-if="!verified"
            class="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
            data-test="notifications-unverified"
        >
            {{ $t('Your email address is not verified, so you get no mail.') }}
        </p>

        <div class="grid gap-3">
            <label
                v-for="event in events"
                :key="event.value"
                class="flex items-center gap-2 text-sm"
                :data-test="`event-${event.value}`"
            >
                <Checkbox
                    :model-value="form.events.includes(event.value)"
                    @update:model-value="
                        (checked) => toggle(event.value, checked === true)
                    "
                />
                {{ $t(event.label) }}
            </label>
            <InputError :message="form.errors.events" />
        </div>

        <Button type="submit" :disabled="form.processing">
            {{ $t('Save') }}
        </Button>
    </form>
</template>
