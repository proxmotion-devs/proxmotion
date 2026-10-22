<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Sparkline from '@/components/servers/Sparkline.vue';
import { useServerStats } from '@/composables/useServerStats';
import {
    formatBitRate,
    formatByteRate,
    formatBytes,
    percentage,
} from '@/lib/format';

/**
 * KPI cards with what a server is doing right now: CPU, memory, network and
 * disk, each with the last hour (docs/adr/0036).
 */
const props = defineProps<{
    serverUuid: string;
    portSpeedMbps: number | null;
}>();

const { stats, history, live, loaded, failed } = useServerStats(
    props.serverUuid,
);

/** Figures older than this are shown as old (Proxmox stopped answering). */
const STALE_MS = 45_000;

const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 5000);
});

onUnmounted(() => {
    if (clock !== null) {
        clearInterval(clock);
    }
});

const end = computed(() => stats.value?.time ?? now.value);
const stale = computed(
    () =>
        failed.value ||
        (stats.value !== null && now.value - stats.value.time > STALE_MS),
);
const running = computed(() => stats.value?.status === 'running');

const uptime = computed(() => {
    const seconds = stats.value?.uptime ?? 0;
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (days > 0) {
        return trans(':days d :hours h', {
            days: String(days),
            hours: String(hours),
        });
    }

    return hours > 0
        ? trans(':hours h :minutes min', {
              hours: String(hours),
              minutes: String(minutes),
          })
        : trans(':minutes min', { minutes: String(minutes) });
});

const percent = (value: number | null) => (value === null ? '—' : `${value} %`);
const bytes = (value: number | null) => formatBytes(value);

const memoryShare = computed(() =>
    percentage(stats.value?.memory_bytes, stats.value?.memory_total_bytes),
);

// Inbound traffic against the port speed, when the server has one.
const portShare = computed(() => {
    const incoming = stats.value?.net_in;

    if (!props.portSpeedMbps || incoming === null || incoming === undefined) {
        return null;
    }

    return Math.round(((incoming * 8) / (props.portSpeedMbps * 1e6)) * 100);
});
</script>

<template>
    <section class="flex flex-col gap-3" data-test="server-stats">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-medium">{{ $t('Live usage') }}</h3>
            <div
                class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
            >
                <span v-if="stats && running">{{
                    $t('Up :time', { time: uptime })
                }}</span>
                <span v-else-if="stats">{{ $t('Not running') }}</span>
                <span
                    v-if="stale && loaded"
                    class="text-amber-700 dark:text-amber-400"
                    data-test="stats-stale"
                    >{{
                        $t('Proxmox did not answer; the figures may be old.')
                    }}</span
                >
                <span
                    v-else-if="live"
                    class="flex items-center gap-1.5"
                    data-test="stats-live"
                >
                    <span
                        class="inline-block h-2 w-2 rounded-full bg-emerald-500"
                    />
                    {{ $t('Live') }}
                </span>
                <span v-else-if="loaded" data-test="stats-polling">{{
                    $t('Updated every 10 seconds')
                }}</span>
            </div>
        </div>

        <p
            v-if="loaded && !stats"
            class="rounded-xl border p-4 text-sm text-muted-foreground"
            data-test="stats-unavailable"
        >
            {{ $t('Proxmox gave no figures for this server.') }}
        </p>

        <div
            v-else
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
            :class="{ 'opacity-60': stale }"
        >
            <div
                class="flex flex-col gap-1 rounded-xl border p-4"
                data-test="stats-cpu"
            >
                <div class="text-sm text-muted-foreground">
                    {{ $t('CPU') }}
                </div>
                <div class="text-2xl font-semibold">
                    {{ percent(stats?.cpu_percent ?? null) }}
                </div>
                <div class="text-xs text-muted-foreground">
                    {{
                        stats?.cpus
                            ? $t('of :count vCPUs', {
                                  count: String(stats.cpus),
                              })
                            : ' '
                    }}
                </div>
                <Sparkline
                    :points="history"
                    :series="[
                        {
                            key: 'cpu_percent',
                            label: $t('CPU'),
                            color: 'series-1',
                        },
                    ]"
                    :end="end"
                    :max="100"
                    :format="percent"
                />
            </div>

            <div
                class="flex flex-col gap-1 rounded-xl border p-4"
                data-test="stats-memory"
            >
                <div class="text-sm text-muted-foreground">
                    {{ $t('Memory') }}
                </div>
                <div class="text-2xl font-semibold">
                    {{ bytes(stats?.memory_bytes ?? null) }}
                </div>
                <div class="text-xs text-muted-foreground">
                    {{
                        stats?.memory_total_bytes
                            ? $t('of :total (:percent %)', {
                                  total: bytes(stats.memory_total_bytes),
                                  percent: String(memoryShare ?? '—'),
                              })
                            : ' '
                    }}
                </div>
                <Sparkline
                    :points="history"
                    :series="[
                        {
                            key: 'memory_bytes',
                            label: $t('Memory'),
                            color: 'series-1',
                        },
                    ]"
                    :end="end"
                    :max="stats?.memory_total_bytes"
                    :format="bytes"
                />
            </div>

            <div
                class="flex flex-col gap-1 rounded-xl border p-4"
                data-test="stats-network"
            >
                <div class="text-sm text-muted-foreground">
                    {{ $t('Network') }}
                </div>
                <div class="grid gap-0.5 text-sm">
                    <div class="flex items-center gap-1.5">
                        <span
                            class="inline-block h-2 w-2 rounded-full bg-series-1"
                        />
                        <span class="text-muted-foreground">{{
                            $t('In')
                        }}</span>
                        <span class="ml-auto text-base font-semibold">{{
                            formatBitRate(stats?.net_in)
                        }}</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span
                            class="inline-block h-2 w-2 rounded-full bg-series-2"
                        />
                        <span class="text-muted-foreground">{{
                            $t('Out')
                        }}</span>
                        <span class="ml-auto text-base font-semibold">{{
                            formatBitRate(stats?.net_out)
                        }}</span>
                    </div>
                </div>
                <div class="text-xs text-muted-foreground">
                    {{
                        portSpeedMbps
                            ? $t('In: :percent % of the :speed Mbps port', {
                                  percent: String(portShare ?? '—'),
                                  speed: String(portSpeedMbps),
                              })
                            : ' '
                    }}
                </div>
                <Sparkline
                    :points="history"
                    :series="[
                        { key: 'net_in', label: $t('In'), color: 'series-1' },
                        { key: 'net_out', label: $t('Out'), color: 'series-2' },
                    ]"
                    :end="end"
                    :format="formatBitRate"
                />
            </div>

            <div
                class="flex flex-col gap-1 rounded-xl border p-4"
                data-test="stats-disk"
            >
                <div class="text-sm text-muted-foreground">
                    {{ $t('Disk I/O') }}
                </div>
                <div class="grid gap-0.5 text-sm">
                    <div class="flex items-center gap-1.5">
                        <span
                            class="inline-block h-2 w-2 rounded-full bg-series-1"
                        />
                        <span class="text-muted-foreground">{{
                            $t('Read')
                        }}</span>
                        <span class="ml-auto text-base font-semibold">{{
                            formatByteRate(stats?.disk_read)
                        }}</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span
                            class="inline-block h-2 w-2 rounded-full bg-series-2"
                        />
                        <span class="text-muted-foreground">{{
                            $t('Write')
                        }}</span>
                        <span class="ml-auto text-base font-semibold">{{
                            formatByteRate(stats?.disk_write)
                        }}</span>
                    </div>
                </div>
                <div class="text-xs text-muted-foreground">&nbsp;</div>
                <Sparkline
                    :points="history"
                    :series="[
                        {
                            key: 'disk_read',
                            label: $t('Read'),
                            color: 'series-1',
                        },
                        {
                            key: 'disk_write',
                            label: $t('Write'),
                            color: 'series-2',
                        },
                    ]"
                    :end="end"
                    :format="formatByteRate"
                />
            </div>
        </div>
    </section>
</template>
