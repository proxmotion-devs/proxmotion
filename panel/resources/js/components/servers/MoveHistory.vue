<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { RotateCcw } from '@lucide/vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import type {
    ServerMove,
    ServerMoveKind,
    ServerMoveStatus,
} from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * The server's recent moves (docs/adr/0037). A move whose VM Proxmox already
 * moved but whose later steps failed can be finished by a new task.
 */
const props = defineProps<{
    moves: ServerMove[];
    finish: (move: string) => RouteDefinition<'post'>;
    disabled?: boolean;
}>();

const { formatDateTime } = useDateTime();

// English keys, translated in the template.
const kindLabels: Record<ServerMoveKind, string> = {
    node: 'To another node',
    cluster: 'To another cluster',
    location: 'To another location',
};
const statusLabels: Record<ServerMoveStatus, string> = {
    queued: 'Queued',
    running: 'Running',
    succeeded: 'Succeeded',
    failed: 'Failed',
};
const statusTone: Record<
    ServerMoveStatus,
    'success' | 'warning' | 'danger' | 'neutral'
> = {
    queued: 'neutral',
    running: 'warning',
    succeeded: 'success',
    failed: 'danger',
};

const finishMove = (move: ServerMove) =>
    router.visit(props.finish(move.uuid), { preserveScroll: true });
</script>

<template>
    <ul class="flex flex-col gap-2 text-sm" data-test="move-history">
        <li
            v-for="move in moves"
            :key="move.uuid"
            class="grid gap-1 rounded-md border p-2"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium">{{ $t(kindLabels[move.kind]) }}</span>
                <StatusBadge
                    :tone="statusTone[move.status]"
                    :label="statusLabels[move.status]"
                />
            </div>
            <p class="text-xs text-muted-foreground">
                {{ move.from }} → {{ move.to }}
            </p>
            <p v-if="move.created_at" class="text-xs text-muted-foreground">
                {{ formatDateTime(move.created_at) }}
            </p>
            <p v-if="move.error" class="text-xs text-destructive">
                {{ move.error }}
            </p>
            <div v-if="move.can_finish">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="disabled"
                    data-test="move-finish"
                    @click="finishMove(move)"
                >
                    <RotateCcw class="h-4 w-4" />
                    {{ $t('Finish the move') }}
                </Button>
            </div>
        </li>
    </ul>
</template>
