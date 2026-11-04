<script setup lang="ts">
import { onMounted, ref, useId, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    label: string;
    for?: string;
    error?: string;
    hint?: string;
}>();

const field = ref<HTMLElement | null>(null);
const hintId = useId();
const errorId = useId();

/**
 * Links the control to its hint and error (`aria-describedby`) and marks it
 * invalid while there is an error, which also gives it the red border of the
 * inputs. The control is the one named by `for`, else the first one inside.
 */
const describe = () => {
    const root = field.value;

    if (!root) {
        return;
    }

    const control =
        (props.for
            ? root.querySelector<HTMLElement>(`#${CSS.escape(props.for)}`)
            : null) ??
        root.querySelector<HTMLElement>(
            'input:not([type="hidden"]), select, textarea, button[role="combobox"], [role="switch"], [role="checkbox"]',
        );

    if (!control) {
        return;
    }

    const ids = [props.hint ? hintId : null, props.error ? errorId : null]
        .filter((id): id is string => id !== null)
        .join(' ');

    if (ids) {
        control.setAttribute('aria-describedby', ids);
    } else {
        control.removeAttribute('aria-describedby');
    }

    if (props.error) {
        control.setAttribute('aria-invalid', 'true');
    } else {
        control.removeAttribute('aria-invalid');
    }
};

onMounted(describe);
watch(() => [props.error, props.hint, props.for], describe, { flush: 'post' });
</script>

<template>
    <div ref="field" class="grid gap-2">
        <Label :for="$props.for">{{ $t(label) }}</Label>
        <slot />
        <p v-if="hint" :id="hintId" class="text-xs text-muted-foreground">
            {{ $t(hint) }}
        </p>
        <InputError :id="errorId" :message="error" />
    </div>
</template>
