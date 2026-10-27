<script setup lang="ts">
import FormField from '@/components/admin/FormField.vue';
import { Input } from '@/components/ui/input';
import type { OptionalNumber } from '@/lib/forms';

/**
 * How often billing systems may reinstall a server within a rolling window
 * of days (docs/adr/0045); an empty count is unlimited, 0 allows none.
 * Used by the package form and the server's reinstall limit dialog.
 */
defineProps<{
    errors: Partial<Record<string, string>>;
}>();

const limit = defineModel<OptionalNumber>('limit', { required: true });
const days = defineModel<OptionalNumber>('days', { required: true });

const optional = (value: string | number): OptionalNumber =>
    value === '' ? '' : Number(value);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <FormField
            label="Reinstalls"
            for="reinstall_limit"
            :error="errors.reinstall_limit"
            hint="How often the billing system may reinstall a server. Leave empty for unlimited; 0 allows none."
        >
            <Input
                id="reinstall_limit"
                :model-value="limit"
                type="number"
                min="0"
                max="1000"
                @update:model-value="(value) => (limit = optional(value))"
            />
        </FormField>
        <FormField
            label="Within (days)"
            for="reinstall_period_days"
            :error="errors.reinstall_period_days"
            hint="Counted over the last this many days."
        >
            <Input
                id="reinstall_period_days"
                :model-value="days"
                type="number"
                min="1"
                max="365"
                :disabled="limit === ''"
                :required="limit !== ''"
                @update:model-value="(value) => (days = optional(value))"
            />
        </FormField>
    </div>
</template>
