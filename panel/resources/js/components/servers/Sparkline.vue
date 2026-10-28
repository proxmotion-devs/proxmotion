<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { HISTORY_MS } from '@/composables/useServerStats';
import { linePath, nearestPoint } from '@/lib/charts';
import type { ServerStatsPoint } from '@/types/admin';

type Key = Exclude<keyof ServerStatsPoint, 'time'>;

/**
 * A small line chart of the last hour of one or two figures (docs/adr/0036),
 * with the values under the pointer shown on hover.
 */
const props = defineProps<{
    points: ServerStatsPoint[];
    series: { key: Key; label: string; color: 'series-1' | 'series-2' }[];
    /** The newest time shown (ms); the chart spans the hour before it. */
    end: number;
    /** Top of the scale; the largest value shown when not given. */
    max?: number | null;
    format: (value: number | null) => string;
}>();

const WIDTH = 300;
const HEIGHT = 40;
/** A longer gap between two points breaks the line (Proxmox had no data). */
const GAP_MS = 3 * 60 * 1000;

const page = usePage();

const start = computed(() => props.end - HISTORY_MS);
const visible = computed(() =>
    props.points.filter((point) => point.time >= start.value),
);

const top = computed(() => {
    if (props.max) {
        return props.max;
    }

    const values = visible.value.flatMap((point) =>
        props.series.map((series) => point[series.key] ?? 0),
    );

    return Math.max(1, ...values);
});

const x = (time: number) => ((time - start.value) / HISTORY_MS) * WIDTH;
const y = (value: number) =>
    HEIGHT - 1 - (Math.min(value, top.value) / top.value) * (HEIGHT - 2);

const paths = computed(() =>
    props.series.map((series) => ({
        ...series,
        d: linePath(visible.value, (point) => point[series.key], x, y, GAP_MS),
    })),
);

const hovered = ref<ServerStatsPoint | null>(null);

const hover = (event: PointerEvent) => {
    const box = (event.currentTarget as SVGElement).getBoundingClientRect();
    const time =
        start.value + ((event.clientX - box.left) / box.width) * HISTORY_MS;

    hovered.value = nearestPoint(visible.value, time, (point) => point.time);
};

const hoverTime = computed(() =>
    hovered.value === null
        ? ''
        : new Intl.DateTimeFormat(page.props.locale, {
              timeStyle: 'medium',
          }).format(new Date(hovered.value.time)),
);
</script>

<template>
    <div class="relative">
        <svg
            :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
            preserveAspectRatio="none"
            class="h-10 w-full touch-none overflow-visible"
            role="img"
            :aria-label="series.map((item) => item.label).join(', ')"
            @pointermove="hover"
            @pointerleave="hovered = null"
        >
            <line
                :x1="0"
                :x2="WIDTH"
                :y1="HEIGHT - 0.5"
                :y2="HEIGHT - 0.5"
                class="stroke-border"
                vector-effect="non-scaling-stroke"
            />
            <path
                v-for="path in paths"
                :key="path.key"
                :d="path.d"
                fill="none"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
                :class="
                    path.color === 'series-1'
                        ? 'stroke-series-1'
                        : 'stroke-series-2'
                "
            />
            <line
                v-if="hovered"
                :x1="x(hovered.time)"
                :x2="x(hovered.time)"
                :y1="0"
                :y2="HEIGHT"
                class="stroke-muted-foreground"
                stroke-dasharray="2 2"
                vector-effect="non-scaling-stroke"
            />
        </svg>
        <div
            v-if="hovered"
            class="pointer-events-none absolute bottom-full z-10 mb-1 rounded-md border bg-popover px-2 py-1 text-xs whitespace-nowrap text-popover-foreground shadow-sm"
            :style="{
                left: `${Math.min(Math.max((x(hovered.time) / WIDTH) * 100, 15), 85)}%`,
                transform: 'translateX(-50%)',
            }"
            data-test="sparkline-tooltip"
        >
            <div class="text-muted-foreground">{{ hoverTime }}</div>
            <div
                v-for="item in series"
                :key="item.key"
                class="flex items-center gap-1.5"
            >
                <span
                    class="inline-block h-2 w-2 rounded-full"
                    :class="
                        item.color === 'series-1'
                            ? 'bg-series-1'
                            : 'bg-series-2'
                    "
                />
                <span v-if="series.length > 1">{{ item.label }}</span>
                <span class="font-medium">{{ format(hovered[item.key]) }}</span>
            </div>
        </div>
    </div>
</template>
