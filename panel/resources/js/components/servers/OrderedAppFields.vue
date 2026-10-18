<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import AppInputFields from '@/components/servers/AppInputFields.vue';
import type { AppInput, OrderedAppOption } from '@/types/admin';

/**
 * An optional app installed once a new or reinstalled server has booted
 * (docs/adr/0016 §5): only the apps that fit the chosen OS template are
 * listed. The panel checks the rest (resources, network model, inputs).
 */
const props = defineProps<{
    options: OrderedAppOption[];
    osTemplate: string | null;
    errors: Record<string, string>;
}>();

const app = defineModel<string>('app', { required: true });
const inputs = defineModel<Record<string, string | number | boolean>>(
    'inputs',
    { required: true },
);

const available = computed(() =>
    props.options.filter(
        (option) =>
            props.osTemplate !== null &&
            option.os_templates.includes(props.osTemplate),
    ),
);

const selectOptions = computed(() => [
    { value: '', label: trans('No app') },
    ...available.value.map((option) => ({
        value: option.slug,
        label: option.name,
    })),
]);

const selected = computed(() =>
    available.value.find((option) => option.slug === app.value),
);

const defaults = (declared: AppInput[]) =>
    Object.fromEntries(
        declared
            .filter((input) => input.default !== null)
            .map((input) => [
                input.key,
                input.default as string | number | boolean,
            ]),
    );

watch(app, () => {
    inputs.value = defaults(selected.value?.inputs ?? []);
});

// Another OS template may not suit the chosen app.
watch(
    () => props.osTemplate,
    () => {
        if (app.value !== '' && selected.value === undefined) {
            app.value = '';
        }
    },
);
</script>

<template>
    <FormField
        v-if="options.length > 0"
        label="App"
        for="app"
        :error="errors.app"
        hint="Optional. Installed through the guest agent once the server has booted."
    >
        <NativeSelect id="app" v-model="app" :options="selectOptions" />
    </FormField>

    <AppInputFields
        v-if="selected"
        v-model="inputs"
        :inputs="selected.inputs"
        :errors="errors"
        error-prefix="app_inputs"
        id-prefix="app_input"
    />
</template>
