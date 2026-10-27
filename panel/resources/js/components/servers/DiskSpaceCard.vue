<script setup lang="ts">
import { CircleAlert, HardDrive } from '@lucide/vue';
import { computed } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import { formatBytes, percentage } from '@/lib/format';
import type { ServerDiskUsage } from '@/types/admin';

/**
 * How full a running server's file systems are, from its guest agent
 * (docs/adr/0036 §7): the root one first, then the others it has mounted,
 * each in a card of its own like the usage cards above it.
 * Null while the panel has not asked the guest agent yet.
 */
const props = defineProps<{
    disk: ServerDiskUsage | null;
}>();

/** Filling up from 80 %, nearly full from 90 %. */
type Level = 'ok' | 'filling' | 'full';

const level = (percent: number): Level =>
    percent < 80 ? 'ok' : percent < 90 ? 'filling' : 'full';

const rows = computed(() =>
    (props.disk?.file_systems ?? []).map((fileSystem) => {
        const percent =
            percentage(fileSystem.used_bytes, fileSystem.total_bytes) ?? 0;

        return {
            ...fileSystem,
            percent,
            level: level(percent),
            freeBytes: Math.max(
                0,
                fileSystem.total_bytes - fileSystem.used_bytes,
            ),
        };
    }),
);

const barClass = {
    ok: 'bg-primary',
    filling: 'bg-amber-500',
    full: 'bg-destructive',
} as const;

const textClass = {
    ok: '',
    filling: 'text-amber-700 dark:text-amber-400',
    full: 'text-destructive',
} as const;
</script>

<template>
    <div class="flex flex-col gap-3" data-test="stats-disk-space">
        <div
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1"
        >
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <HardDrive class="size-4" aria-hidden="true" />
                {{ $t('Disk space') }}
            </div>
            <div class="text-xs text-muted-foreground">
                {{ $t('From the guest agent, checked every minute') }}
            </div>
        </div>

        <div
            v-if="!disk"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
            data-test="disk-space-loading"
            :aria-label="$t('Asking the guest agent for the disk space…')"
            role="status"
        >
            <div class="flex flex-col gap-2 rounded-xl border p-4">
                <Skeleton class="h-5 w-16" />
                <Skeleton class="h-8 w-20" />
                <Skeleton class="h-2 w-full rounded-full" />
                <Skeleton class="h-4 w-24" />
            </div>
        </div>
        <p
            v-else-if="rows.length === 0"
            class="flex items-center gap-2 rounded-xl border p-4 text-sm text-muted-foreground"
            :data-test="
                disk.available ? 'disk-space-empty' : 'disk-space-unavailable'
            "
        >
            <CircleAlert class="size-4 shrink-0" aria-hidden="true" />
            {{
                disk.available
                    ? $t('The guest agent reported no file systems.')
                    : $t(
                          'The guest agent did not answer, so the disk space is unknown.',
                      )
            }}
        </p>

        <ul v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <li
                v-for="(fileSystem, index) in rows"
                :key="fileSystem.mountpoint"
                class="flex min-w-0 flex-col gap-2 rounded-xl border p-4"
                data-test="disk-space-file-system"
            >
                <div class="flex min-w-0 items-center justify-between gap-2">
                    <span
                        class="truncate font-mono text-sm"
                        :class="{ 'font-semibold': index === 0 }"
                        :title="fileSystem.mountpoint"
                        >{{ fileSystem.mountpoint }}</span
                    >
                    <span
                        class="shrink-0 rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground"
                        >{{ fileSystem.type }}</span
                    >
                </div>

                <div
                    class="flex flex-wrap items-baseline justify-between gap-x-3"
                >
                    <span
                        class="text-2xl font-semibold tabular-nums"
                        :class="textClass[fileSystem.level]"
                        >{{ fileSystem.percent }} %</span
                    >
                    <span class="text-sm tabular-nums">
                        <span class="font-medium">{{
                            formatBytes(fileSystem.used_bytes)
                        }}</span>
                        <span class="text-muted-foreground">
                            {{
                                $t('of :total', {
                                    total: formatBytes(fileSystem.total_bytes),
                                })
                            }}</span
                        >
                    </span>
                </div>

                <div
                    class="h-2 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-label="fileSystem.mountpoint"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="fileSystem.percent"
                >
                    <div
                        class="h-full rounded-full transition-[width]"
                        :class="barClass[fileSystem.level]"
                        :style="{
                            width: `${Math.min(100, fileSystem.percent)}%`,
                        }"
                    />
                </div>

                <div class="text-xs text-muted-foreground tabular-nums">
                    {{
                        $t(':size free', {
                            size: formatBytes(fileSystem.freeBytes),
                        })
                    }}
                </div>
            </li>
        </ul>
    </div>
</template>
