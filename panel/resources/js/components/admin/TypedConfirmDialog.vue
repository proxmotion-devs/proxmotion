<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { computed, ref, useId } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { RouteDefinition } from '@/wayfinder';

// The trigger button takes attributes such as data-test; the dialog does not.
defineOptions({ inheritAttrs: false });

/**
 * Confirms an action that cannot be undone by typing the server's hostname
 * (AGENTS.md §7.8): the button stays disabled until it matches, and the
 * server checks `confirm_hostname` again. Title, description and labels are
 * English keys, translated here.
 */
const props = defineProps<{
    action: RouteDefinition<'post'> | RouteDefinition<'delete'>;
    hostname: string;
    title: string;
    description: string;
    /** Text of the button that opens the dialog. */
    label: string;
    submitLabel: string;
    icon?: Component;
    disabled?: boolean;
    /** Red trigger and submit button, for deleting. */
    destructive?: boolean;
}>();

const open = ref(false);
const form = useForm({ confirm_hostname: '' });
const inputId = `confirm-hostname-${useId()}`;

// Matched like the server does: trimmed, case-insensitive.
const matches = computed(
    () =>
        form.confirm_hostname.trim().toLowerCase() ===
        props.hostname.toLowerCase(),
);

const openDialog = () => {
    form.clearErrors();
    form.reset();
    open.value = true;
};

const submit = () => {
    if (!matches.value) {
        return;
    }

    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
};
</script>

<template>
    <Button
        variant="outline"
        size="sm"
        :class="
            destructive
                ? 'text-destructive hover:bg-destructive/10 hover:text-destructive'
                : undefined
        "
        :disabled="disabled"
        v-bind="$attrs"
        @click="openDialog"
    >
        <component :is="icon" v-if="icon" class="h-4 w-4" />
        {{ $t(label) }}
    </Button>

    <FormDialog
        v-model:open="open"
        :title="title"
        :description="description"
        :submit-label="submitLabel"
        :processing="form.processing"
        :destructive="destructive"
        :submit-disabled="!matches"
        @submit="submit"
    >
        <FormField
            label="Type the hostname to confirm"
            :for="inputId"
            :error="form.errors.confirm_hostname"
        >
            <Input
                :id="inputId"
                v-model="form.confirm_hostname"
                class="font-mono"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                required
                :placeholder="hostname"
            />
        </FormField>
    </FormDialog>
</template>
