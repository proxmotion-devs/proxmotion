<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

defineProps<{
    label: string;
    for?: string;
    error?: string;
    hint?: string;
}>();
</script>

<template>
    <div class="grid gap-2">
        <Label :for="$props.for">{{ $t(label) }}</Label>
        <slot />
        <p v-if="hint" class="text-xs text-muted-foreground">{{ $t(hint) }}</p>
        <InputError :message="error" />
    </div>
</template>
