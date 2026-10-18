<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AppInput, AppText } from '@/types/admin';

/**
 * Fields for the declared inputs of an app or one of its actions
 * (docs/adr/0015, 0016); the panel validates the values again.
 */
withDefaults(
    defineProps<{
        inputs: AppInput[];
        errors: Record<string, string>;
        idPrefix: string;
        // Where the panel puts the errors: <errorPrefix>.<key>.
        errorPrefix?: string;
    }>(),
    { errorPrefix: 'inputs' },
);

const values = defineModel<Record<string, string | number | boolean>>({
    required: true,
});

const page = usePage<{ locale: string }>();

const text = (value: AppText | null, fallback: string) =>
    value
        ? ((page.props.locale === 'tr' ? value.tr : null) ?? value.en)
        : fallback;

const inputType = (input: AppInput) =>
    ({
        password: 'password',
        integer: 'number',
        port: 'number',
        email: 'email',
    })[input.type as string] ?? 'text';
</script>

<template>
    <template v-for="input in inputs" :key="input.key">
        <Label
            v-if="input.type === 'boolean'"
            class="flex items-center gap-3 font-normal"
        >
            <Checkbox
                :model-value="values[input.key] === true"
                @update:model-value="values[input.key] = $event === true"
            />
            {{ text(input.label, input.key) }}
        </Label>
        <FormField
            v-else
            :label="text(input.label, input.key)"
            :for="`${idPrefix}_${input.key}`"
            :error="errors[`${errorPrefix}.${input.key}`]"
        >
            <NativeSelect
                v-if="input.type === 'enum'"
                :id="`${idPrefix}_${input.key}`"
                v-model="values[input.key] as string"
                :options="
                    (input.options ?? []).map((value) => ({
                        value,
                        label: value,
                    }))
                "
            />
            <Input
                v-else
                :id="`${idPrefix}_${input.key}`"
                v-model="values[input.key] as string"
                :type="inputType(input)"
                :min="input.min ?? undefined"
                :max="input.max ?? undefined"
                :maxlength="input.max_length ?? undefined"
                :required="input.required"
                autocomplete="off"
            />
        </FormField>
    </template>
</template>
