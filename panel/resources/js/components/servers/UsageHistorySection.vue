<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import type { ChartPoint } from '@/components/servers/UsageChart.vue';
import UsageChart from '@/components/servers/UsageChart.vue';
import { formatBitRate, formatByteRate, formatTraffic } from '@/lib/format';
import { usage as usageRoute } from '@/routes/admin/servers';
import type { UsageHistory, UsagePoint, UsageRange } from '@/types/admin';

/**
 * A server's usage over the last day, week, month or year (docs/adr/0050):
 * CPU, memory, network and disk I/O as lines, traffic as bars.
 */
const props = defineProps<{
    serverUuid: string;
}>();

// English keys, translated in the template.
const RANGES: { value: UsageRange; label: string }[] = [
    { value: 'day', label: '24 hours' },
    { value: 'week', label: '7 days' },
    { value: 'month', label: '30 days' },
    { value: 'year', label: '12 months' },
];

const range = ref<UsageRange>('day');
const history = ref<UsageHistory | null>(null);
const loading = ref(false);
const failed = ref(false);
let request = 0;

async function load(): Promise<void> {
    const current = ++request;
    loading.value = true;

    try {
        const response = await fetch(
            usageRoute.url(props.serverUuid, {
                query: { range: range.value },
            }),
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const answer = (await response.json()) as UsageHistory;

        // A slower answer for a range no longer chosen is dropped.
        if (current === request) {
            history.value = answer;
            failed.value = false;
        }
    } catch {
        if (current === request) {
            failed.value = true;
        }
    } finally {
        if (current === request) {
            loading.value = false;
        }
    }
}

onMounted(load);
watch(range, load);

const from = computed(() =>
    history.value ? Date.parse(history.value.from) : 0,
);
const to = computed(() => (history.value ? Date.parse(history.value.to) : 0));
/** A longer gap than one and a half points breaks a line. */
const gapMs = computed(() => (history.value?.interval_seconds ?? 300) * 1500);

type Figure = Exclude<keyof UsagePoint, 'time'>;

const points = (...keys: Figure[]): ChartPoint[] =>
    (history.value?.points ?? []).map((point) => ({
        time: Date.parse(point.time),
        values: Object.fromEntries(keys.map((key) => [key, point[key]])),
    }));

const trafficBars = computed<ChartPoint[]>(() =>
    (history.value?.traffic.buckets ?? []).map((bucket) => ({
        time: Date.parse(bucket.start),
        end: Date.parse(bucket.end),
        values: { in: bucket.in_bytes, out: bucket.out_bytes },
    })),
);

const totals = computed(() =>
    (history.value?.traffic.buckets ?? []).reduce(
        (sum, bucket) => ({
            in: sum.in + bucket.in_bytes,
            out: sum.out + bucket.out_bytes,
        }),
        { in: 0, out: 0 },
    ),
);

const timeFormat = computed<Intl.DateTimeFormatOptions>(() =>
    range.value === 'day'
        ? { timeStyle: 'short' }
        : range.value === 'year'
          ? { dateStyle: 'medium' }
          : { dateStyle: 'short', timeStyle: 'short' },
);

const trafficTimeFormat = computed<Intl.DateTimeFormatOptions>(() =>
    range.value === 'day'
        ? { timeStyle: 'short' }
        : range.value === 'year'
          ? { month: 'short', year: 'numeric' }
          : { dateStyle: 'medium' },
);

// English keys, translated in the template.
const TRAFFIC_INTERVALS = {
    hour: 'Traffic per hour',
    day: 'Traffic per day',
    period: 'Traffic per period',
} as const;

const percent = (value: number | null) => (value === null ? '—' : `${value} %`);
const traffic = (value: number | null) => formatTraffic(value ?? 0);
</script>

<template>
    <section class="flex flex-col gap-4" data-test="usage-history">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="font-medium">{{ $t('Usage history') }}</h3>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t('Averages of what Proxmox reported every 5 minutes.')
                    }}
                </p>
            </div>
            <div
                class="inline-flex rounded-md border p-0.5"
                role="group"
                :aria-label="$t('Range')"
            >
                <button
                    v-for="item in RANGES"
                    :key="item.value"
                    type="button"
                    class="rounded px-3 py-1 text-sm"
                    :class="
                        range === item.value
                            ? 'bg-muted font-medium'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="range === item.value"
                    :data-test="`usage-range-${item.value}`"
                    @click="range = item.value"
                >
                    {{ $t(item.label) }}
                </button>
            </div>
        </div>

        <p
            v-if="failed"
            class="rounded-xl border p-4 text-sm text-destructive"
            data-test="usage-failed"
        >
            {{ $t('The usage history could not be loaded.') }}
        </p>

        <p
            v-else-if="history && history.points.length === 0"
            class="rounded-xl border p-4 text-sm text-muted-foreground"
            data-test="usage-empty"
        >
            {{ $t('Nothing was recorded for this server in this range yet.') }}
        </p>

        <div
            v-else-if="history"
            class="grid gap-4 lg:grid-cols-2"
            :class="{ 'opacity-60': loading }"
        >
            <div class="flex flex-col gap-2 rounded-xl border p-4">
                <div class="text-sm text-muted-foreground">
                    {{ $t('CPU') }}
                </div>
                <UsageChart
                    type="line"
                    :points="points('cpu_percent')"
                    :series="[
                        {
                            key: 'cpu_percent',
                            label: $t('CPU'),
                            color: 'series-1',
                        },
                    ]"
                    :from="from"
                    :to="to"
                    :gap-ms="gapMs"
                    :max="100"
                    :format="percent"
                    :time-format="timeFormat"
                />
            </div>

            <div class="flex flex-col gap-2 rounded-xl border p-4">
                <div class="text-sm text-muted-foreground">
                    {{ $t('Memory') }}
                </div>
                <UsageChart
                    type="line"
                    :points="points('memory_percent')"
                    :series="[
                        {
                            key: 'memory_percent',
                            label: $t('Memory'),
                            color: 'series-1',
                        },
                    ]"
                    :from="from"
                    :to="to"
                    :gap-ms="gapMs"
                    :max="100"
                    :format="percent"
                    :time-format="timeFormat"
                />
            </div>

            <div class="flex flex-col gap-2 rounded-xl border p-4">
                <div class="flex items-center gap-3 text-sm">
                    <span class="text-muted-foreground">{{
                        $t('Network')
                    }}</span>
                    <span class="flex items-center gap-1.5 text-xs">
                        <span
                            class="inline-block h-2 w-2 rounded-full bg-series-1"
                        />{{ $t('In') }}
                    </span>
                    <span class="flex items-center gap-1.5 text-xs">
                        <span
                            class="inline-block h-2 w-2 rounded-full bg-series-2"
                        />{{ $t('Out') }}
                    </span>
                </div>
                <UsageChart
                    type="line"
                    :points="points('net_in_rate', 'net_out_rate')"
                    :series="[
                        {
                            key: 'net_in_rate',
                            label: $t('In'),
                            color: 'series-1',
                        },
                        {
                            key: 'net_out_rate',
                            label: $t('Out'),
                            color: 'series-2',
                        },
                    ]"
                    :from="from"
                    :to="to"
                    :gap-ms="gapMs"
                    :format="formatBitRate"
                    :time-format="timeFormat"
                />
            </div>

            <div class="flex flex-col gap-2 rounded-xl border p-4">
                <div class="flex items-center gap-3 text-sm">
                    <span class="text-muted-foreground">{{
                        $t('Disk I/O')
                    }}</span>
                    <span class="flex items-center gap-1.5 text-xs">
                        <span
                            class="inline-block h-2 w-2 rounded-full bg-series-1"
                        />{{ $t('Read') }}
                    </span>
                    <span class="flex items-center gap-1.5 text-xs">
                        <span
                            class="inline-block h-2 w-2 rounded-full bg-series-2"
                        />{{ $t('Write') }}
                    </span>
                </div>
                <UsageChart
                    type="line"
                    :points="points('disk_read_rate', 'disk_write_rate')"
                    :series="[
                        {
                            key: 'disk_read_rate',
                            label: $t('Read'),
                            color: 'series-1',
                        },
                        {
                            key: 'disk_write_rate',
                            label: $t('Write'),
                            color: 'series-2',
                        },
                    ]"
                    :from="from"
                    :to="to"
                    :gap-ms="gapMs"
                    :format="formatByteRate"
                    :time-format="timeFormat"
                />
            </div>

            <div
                class="flex flex-col gap-2 rounded-xl border p-4 lg:col-span-2"
                data-test="usage-traffic"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 text-sm"
                >
                    <div class="flex items-center gap-3">
                        <span class="text-muted-foreground">{{
                            $t(TRAFFIC_INTERVALS[history.traffic.interval])
                        }}</span>
                        <span class="flex items-center gap-1.5 text-xs">
                            <span
                                class="inline-block h-2 w-2 rounded-full bg-series-1"
                            />{{ $t('In') }}
                        </span>
                        <span class="flex items-center gap-1.5 text-xs">
                            <span
                                class="inline-block h-2 w-2 rounded-full bg-series-2"
                            />{{ $t('Out') }}
                        </span>
                    </div>
                    <span
                        class="text-xs text-muted-foreground"
                        data-test="usage-traffic-total"
                    >
                        {{
                            $t('In :in · out :out', {
                                in: formatTraffic(totals.in),
                                out: formatTraffic(totals.out),
                            })
                        }}
                    </span>
                </div>
                <UsageChart
                    type="bar"
                    :points="trafficBars"
                    :series="[
                        { key: 'in', label: $t('In'), color: 'series-1' },
                        { key: 'out', label: $t('Out'), color: 'series-2' },
                    ]"
                    :from="from"
                    :to="to"
                    :gap-ms="gapMs"
                    :format="traffic"
                    :time-format="trafficTimeFormat"
                />
            </div>
        </div>

        <p v-else class="text-sm text-muted-foreground">
            {{ $t('Loading…') }}
        </p>
    </section>
</template>
