<script setup lang="ts">
import { formatBytes, percentage } from '@/lib/format';
import type { ServerDiskUsage } from '@/types/admin';

/**
 * How full a running server's file systems are, from its guest agent
 * (docs/adr/0036 §7): the root one first, then the others it has mounted.
 */
defineProps<{
    disk: ServerDiskUsage;
}>();

const share = (used: number, total: number) => percentage(used, total) ?? 0;

const barClass = (percent: number) =>
    percent < 80
        ? 'bg-primary'
        : percent < 90
          ? 'bg-amber-500'
          : 'bg-destructive';
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-xl border p-4"
        data-test="stats-disk-space"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div class="text-sm text-muted-foreground">
                {{ $t('Disk space') }}
            </div>
            <div class="text-xs text-muted-foreground">
                {{ $t('From the guest agent, checked every minute') }}
            </div>
        </div>

        <p
            v-if="!disk.available"
            class="text-sm text-muted-foreground"
            data-test="disk-space-unavailable"
        >
            {{
                $t(
                    'The guest agent did not answer, so the disk space is unknown.',
                )
            }}
        </p>
        <p
            v-else-if="disk.file_systems.length === 0"
            class="text-sm text-muted-foreground"
            data-test="disk-space-empty"
        >
            {{ $t('The guest agent reported no file systems.') }}
        </p>

        <ul v-else class="grid gap-3">
            <li
                v-for="(fileSystem, index) in disk.file_systems"
                :key="fileSystem.mountpoint"
                class="grid gap-1.5"
                data-test="disk-space-file-system"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 text-sm"
                >
                    <span class="flex min-w-0 items-baseline gap-2">
                        <span
                            class="truncate font-mono"
                            :class="{ 'font-semibold': index === 0 }"
                            >{{ fileSystem.mountpoint }}</span
                        >
                        <span class="text-xs text-muted-foreground">{{
                            fileSystem.type
                        }}</span>
                    </span>
                    <span>
                        <span :class="{ 'font-semibold': index === 0 }">{{
                            formatBytes(fileSystem.used_bytes)
                        }}</span>
                        <span class="text-muted-foreground">
                            {{
                                $t('of :total (:percent %)', {
                                    total: formatBytes(fileSystem.total_bytes),
                                    percent: String(
                                        share(
                                            fileSystem.used_bytes,
                                            fileSystem.total_bytes,
                                        ),
                                    ),
                                })
                            }}
                        </span>
                    </span>
                </div>
                <div
                    class="h-2 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-label="fileSystem.mountpoint"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="
                        share(fileSystem.used_bytes, fileSystem.total_bytes)
                    "
                >
                    <div
                        class="h-full rounded-full"
                        :class="
                            barClass(
                                share(
                                    fileSystem.used_bytes,
                                    fileSystem.total_bytes,
                                ),
                            )
                        "
                        :style="{
                            width: `${Math.min(100, share(fileSystem.used_bytes, fileSystem.total_bytes))}%`,
                        }"
                    />
                </div>
            </li>
        </ul>
    </div>
</template>
