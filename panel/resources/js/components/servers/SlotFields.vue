<script setup lang="ts">
import FormField from '@/components/admin/FormField.vue';
import { Input } from '@/components/ui/input';

/**
 * How many snapshots and backups a custom server may have (docs/adr/0051
 * §2); 0 turns the feature off for it. Used by the custom create form and
 * the Change resources dialog; packages have the same fields.
 */
defineProps<{
    errors: Partial<Record<string, string>>;
}>();

const snapshots = defineModel<number>('snapshots', { required: true });
const backups = defineModel<number>('backups', { required: true });
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <FormField
            label="Snapshots"
            for="snapshot_slots"
            :error="errors.snapshot_slots"
            hint="How many snapshots the server may have. 0: none."
        >
            <Input
                id="snapshot_slots"
                :model-value="snapshots"
                type="number"
                min="0"
                max="100"
                @update:model-value="
                    (value) => (snapshots = Number(value) || 0)
                "
            />
        </FormField>
        <FormField
            label="Backups"
            for="backup_slots"
            :error="errors.backup_slots"
            hint="How many backups the server may have. 0: none."
        >
            <Input
                id="backup_slots"
                :model-value="backups"
                type="number"
                min="0"
                max="100"
                @update:model-value="(value) => (backups = Number(value) || 0)"
            />
        </FormField>
    </div>
</template>
