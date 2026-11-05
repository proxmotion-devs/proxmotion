<script setup lang="ts">
import { computed } from 'vue';

/**
 * A listed server's power state as Proxmox reports it now: a small dot and
 * a word ("Powered on", "Powered off", or "Power state unknown" when its
 * cluster did not answer). Nothing while the state is still loading.
 */
const props = defineProps<{
    /** undefined: still loading; null: unknown. */
    power: string | null | undefined;
}>();

const state = computed(() => {
    if (props.power === 'running') {
        return { label: 'Powered on', dot: 'bg-status-success-fill' };
    }

    if (props.power === 'stopped') {
        return { label: 'Powered off', dot: 'bg-muted-foreground/60' };
    }

    return { label: 'Power state unknown', dot: null };
});
</script>

<template>
    <span
        v-if="power !== undefined"
        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
        data-test="server-power"
    >
        <span
            v-if="state.dot"
            class="inline-block size-2 shrink-0 rounded-full"
            :class="state.dot"
            aria-hidden="true"
        />
        {{ $t(state.label) }}
    </span>
</template>
