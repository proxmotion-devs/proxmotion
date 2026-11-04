<script setup lang="ts">
import { RotateCcw } from '@lucide/vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { usePendingVisit } from '@/lib/pendingVisit';
import { useDateTime } from '@/composables/useDateTime';
import type {
    ServerMove,
    ServerMoveKind,
    ServerMoveStatus,
    StatusTone,
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
const statusTone: Record<ServerMoveStatus, StatusTone> = {
    queued: 'neutral',
    running: 'progress',
    succeeded: 'success',
    failed: 'danger',
};

const { pending, busy, visit } = usePendingVisit();
const finishMove = (move: ServerMove) =>
    visit(move.uuid, props.finish(move.uuid));
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
                    :disabled="disabled || pending !== null"
                    data-test="move-finish"
                    @click="finishMove(move)"
                >
                    <Spinner v-if="busy(move.uuid)" />
                    <RotateCcw v-else class="h-4 w-4" />
                    {{ $t('Finish the move') }}
                </Button>
            </div>
        </li>
    </ul>
</template>
