<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { linePath, nearestPoint } from '@/lib/charts';

/** A point of the chart: its start (ms), for bars its end, and a value per series. */
export type ChartPoint = {
    time: number;
    end?: number;
    values: Record<string, number | null>;
};

/**
 * A line or stacked bar chart of a server's usage over a range
 * (docs/adr/0050), with the values under the pointer shown on hover.
 * Lines break where two points are further apart than `gapMs`.
 */
const props = defineProps<{
    type: 'line' | 'bar';
    points: ChartPoint[];
    series: { key: string; label: string; color: 'series-1' | 'series-2' }[];
    /** The range shown (ms). */
    from: number;
    to: number;
    gapMs: number;
    /** Top of the scale; the largest value (or stack) shown when not given. */
    max?: number | null;
    format: (value: number | null) => string;
    timeFormat: Intl.DateTimeFormatOptions;
}>();

const WIDTH = 600;
const HEIGHT = 160;

const page = usePage();

const span = computed(() => Math.max(1, props.to - props.from));

const top = computed(() => {
    if (props.max) {
        return props.max;
    }

    const values = props.points.map((point) =>
        props.type === 'bar'
            ? props.series.reduce(
                  (sum, series) => sum + (point.values[series.key] ?? 0),
                  0,
              )
            : Math.max(
                  0,
                  ...props.series.map(
                      (series) => point.values[series.key] ?? 0,
                  ),
              ),
    );

    return Math.max(1, ...values);
});

const x = (time: number) =>
    Math.min(Math.max((time - props.from) / span.value, 0), 1) * WIDTH;
const y = (value: number) =>
    HEIGHT - 1 - (Math.min(value, top.value) / top.value) * (HEIGHT - 2);

const paths = computed(() =>
    props.series.map((series) => ({
        ...series,
        d: linePath(
            props.points,
            (point) => point.values[series.key],
            x,
            y,
            props.gapMs,
        ),
    })),
);

const bars = computed(() =>
    props.points.flatMap((point) => {
        const left = x(point.time);
        const width = Math.max(
            1,
            x(point.end ?? point.time + props.gapMs) - left - 1,
        );
        let base = 0;

        return props.series.map((series) => {
            const value = point.values[series.key] ?? 0;
            const bottom = y(base);
            base += value;

            return {
                key: `${point.time}-${series.key}`,
                color: series.color,
                x: left,
                y: y(base),
                width,
                height: Math.max(0, bottom - y(base)),
            };
        });
    }),
);

const formatTime = (time: number) =>
    new Intl.DateTimeFormat(page.props.locale, props.timeFormat).format(
        new Date(time),
    );

const ticks = computed(() =>
    [0, 0.5, 1].map((share) => formatTime(props.from + share * span.value)),
);

// Bars are pointed at by their middle, lines by their time.
const middle = (point: ChartPoint) =>
    props.type === 'bar' && point.end
        ? (point.time + point.end) / 2
        : point.time;

const hovered = ref<ChartPoint | null>(null);

const hover = (event: PointerEvent) => {
    const box = (event.currentTarget as SVGElement).getBoundingClientRect();
    const time =
        props.from + ((event.clientX - box.left) / box.width) * span.value;

    hovered.value = nearestPoint(props.points, time, middle);
};

const hoverX = computed(() => {
    const point = hovered.value;

    return point === null ? 0 : x(middle(point));
});
</script>

<template>
    <div class="grid gap-1">
        <div class="relative">
            <svg
                :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
                preserveAspectRatio="none"
                class="h-40 w-full touch-none overflow-visible"
                role="img"
                :aria-label="series.map((item) => item.label).join(', ')"
                @pointermove="hover"
                @pointerleave="hovered = null"
            >
                <line
                    v-for="share in [0, 0.5]"
                    :key="share"
                    :x1="0"
                    :x2="WIDTH"
                    :y1="share * HEIGHT + 0.5"
                    :y2="share * HEIGHT + 0.5"
                    class="stroke-border"
                    stroke-dasharray="3 3"
                    vector-effect="non-scaling-stroke"
                />
                <line
                    :x1="0"
                    :x2="WIDTH"
                    :y1="HEIGHT - 0.5"
                    :y2="HEIGHT - 0.5"
                    class="stroke-border"
                    vector-effect="non-scaling-stroke"
                />
                <template v-if="type === 'bar'">
                    <rect
                        v-for="bar in bars"
                        :key="bar.key"
                        :x="bar.x"
                        :y="bar.y"
                        :width="bar.width"
                        :height="bar.height"
                        :class="
                            bar.color === 'series-1'
                                ? 'fill-series-1'
                                : 'fill-series-2'
                        "
                    />
                </template>
                <template v-else>
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
                </template>
                <line
                    v-if="hovered"
                    :x1="hoverX"
                    :x2="hoverX"
                    :y1="0"
                    :y2="HEIGHT"
                    class="stroke-muted-foreground"
                    stroke-dasharray="2 2"
                    vector-effect="non-scaling-stroke"
                />
            </svg>
            <span
                class="pointer-events-none absolute top-0 left-1 text-xs text-muted-foreground"
                data-test="usage-chart-top"
                >{{ format(top) }}</span
            >
            <div
                v-if="hovered"
                class="pointer-events-none absolute bottom-full z-10 mb-1 rounded-md border bg-popover px-2 py-1 text-xs whitespace-nowrap text-popover-foreground shadow-sm"
                :style="{
                    left: `${Math.min(Math.max((hoverX / WIDTH) * 100, 15), 85)}%`,
                    transform: 'translateX(-50%)',
                }"
                data-test="usage-chart-tooltip"
            >
                <div class="text-muted-foreground">
                    {{ formatTime(hovered.time) }}
                </div>
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
                    <span class="font-medium">{{
                        format(hovered.values[item.key] ?? null)
                    }}</span>
                </div>
            </div>
        </div>
        <div class="flex justify-between text-xs text-muted-foreground">
            <span v-for="(tick, index) in ticks" :key="index">{{ tick }}</span>
        </div>
    </div>
</template>
