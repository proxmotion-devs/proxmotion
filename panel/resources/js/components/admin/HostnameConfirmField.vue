<script setup lang="ts">
import FormField from '@/components/admin/FormField.vue';
import { Input } from '@/components/ui/input';

/**
 * The field of a confirmation by hostname (AGENTS.md §7.8). The hostname is
 * shown as text above it, never as a placeholder: a placeholder in the
 * input's own font looks as if it had been typed already.
 */
defineProps<{
    id: string;
    hostname: string;
    error?: string;
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <div class="grid gap-3">
        <p class="text-sm" data-test="confirm-hostname-target">
            <span class="text-muted-foreground">{{ $t('Hostname') }}:</span>
            {{ ' ' }}
            <code class="font-mono break-all select-all">{{ hostname }}</code>
        </p>
        <FormField
            label="Type the hostname to confirm"
            :for="id"
            :error="error"
        >
            <Input
                :id="id"
                v-model="model"
                class="font-mono"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                required
            />
        </FormField>
    </div>
</template>
