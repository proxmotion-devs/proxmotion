<script setup lang="ts">
import { ListChecks, RotateCcw } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import Notice from '@/components/admin/Notice.vue';
import RebuildDialog from '@/components/servers/RebuildDialog.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useDateTime } from '@/composables/useDateTime';
import { usePendingVisit } from '@/lib/pendingVisit';
import { TASK_TYPE_LABELS } from '@/lib/taskTypes';
import type { TaskProgress, TaskType } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Why a server is in error (or stuck in a busy status with no task running),
 * with what can be done about it, at the top of its page: the failed task's
 * error and Retry / Rebuild from scratch, so nobody has to find the cause on
 * the Tasks tab first.
 */
const props = defineProps<{
    /** The server's tasks, newest first. */
    tasks: TaskProgress[];
    hostname: string;
    canRetry: boolean;
    retry: RouteDefinition<'post'>;
    /** Set when a failed creation may be started over (docs/adr/0032 §7). */
    rebuild: RouteDefinition<'post'> | null;
}>();

defineEmits<{ showTasks: [] }>();

const { formatDateTime } = useDateTime();
const { busy, pending, visit } = usePendingVisit();

// What the server's Retry runs again (ServerController::retry()).
const RETRIED: TaskType[] = ['terminate', 'reinstall'];

const latest = computed(() => props.tasks[0] ?? null);
const failed = computed(() =>
    latest.value?.status === 'failed' ? latest.value : null,
);
const retried = computed<TaskType>(() =>
    latest.value && RETRIED.includes(latest.value.type)
        ? latest.value.type
        : 'create',
);

const headline = computed(() => {
    if (failed.value === null) {
        return trans(
            'No task is running, but the server is still marked as busy.',
        );
    }

    switch (failed.value.type) {
        case 'create':
            return trans('Creating the server failed.');
        case 'reinstall':
            return trans('The reinstall failed.');
        case 'terminate':
            return trans('Deleting the server failed.');
        default:
            return trans(':task failed.', {
                task: trans(TASK_TYPE_LABELS[failed.value.type]),
            });
    }
});

const retryHint = computed(() => {
    switch (retried.value) {
        case 'reinstall':
            return trans(
                'Retry starts the reinstall again; what is already done is kept.',
            );
        case 'terminate':
            return trans(
                'Retry starts the deletion again; what is already done is kept.',
            );
        default:
            return trans(
                'Retry starts the creation again; what is already done is kept.',
            );
    }
});
</script>

<template>
    <Notice tone="danger" data-test="server-failure">
        <div class="flex flex-col gap-2">
            <p class="flex flex-wrap items-baseline gap-x-2">
                <span class="font-medium">{{ headline }}</span>
                <span
                    v-if="failed?.finished_at"
                    class="text-xs tabular-nums"
                    data-test="server-failure-time"
                    >{{ formatDateTime(failed.finished_at) }}</span
                >
            </p>
            <p
                v-if="failed?.error"
                class="font-medium"
                data-test="server-failure-error"
            >
                {{ failed.error }}
            </p>
            <p>
                <template v-if="canRetry">{{ retryHint }}</template>
                <template v-if="rebuild">
                    {{
                        $t(
                            'If Retry keeps failing on a broken VM, Rebuild from scratch deletes that VM and creates the server again.',
                        )
                    }}
                </template>
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="canRetry"
                    variant="outline"
                    size="sm"
                    class="bg-background"
                    :disabled="pending !== null"
                    data-test="server-retry"
                    @click="visit('retry', retry)"
                >
                    <Spinner v-if="busy('retry')" />
                    <RotateCcw v-else class="h-4 w-4" />
                    {{ $t('Retry') }}
                </Button>
                <RebuildDialog
                    v-if="rebuild"
                    class="bg-background"
                    :action="rebuild"
                    :hostname="hostname"
                    :disabled="pending !== null"
                />
                <Button
                    v-if="failed"
                    variant="outline"
                    size="sm"
                    class="bg-background"
                    data-test="server-failure-tasks"
                    @click="$emit('showTasks')"
                >
                    <ListChecks class="h-4 w-4" />
                    {{ $t('Show the task') }}
                </Button>
            </div>
        </div>
    </Notice>
</template>
