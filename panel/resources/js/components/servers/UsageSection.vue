<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { useDateTime } from '@/composables/useDateTime';
import { formatTraffic, formatUsageValue } from '@/lib/format';
import type { ServerUsage, UsageViolation } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Traffic of the current period against the server's quota, and the latest
 * times it went over its quota or a usage limit (docs/adr/0039).
 */
const props = defineProps<{
    usage: ServerUsage;
    exempt: boolean;
    exemptAction: RouteDefinition<'put'>;
}>();

const { formatDateTime } = useDateTime();

const traffic = computed(() => props.usage.traffic);

const percent = computed(() =>
    traffic.value.quota_bytes
        ? Math.min(
              100,
              Math.round(
                  (traffic.value.counted_bytes / traffic.value.quota_bytes) *
                      100,
              ),
          )
        : null,
);

const barClass = computed(() =>
    percent.value === null || percent.value < 80
        ? 'bg-primary'
        : percent.value < 100
          ? 'bg-amber-500'
          : 'bg-destructive',
);

// English keys, translated in the template.
const actions: Record<UsageViolation['action'], string> = {
    none: 'Recorded only',
    stop: 'Stopped',
    suspend: 'Suspended',
};

const amount = (violation: UsageViolation, value: number): string =>
    formatUsageValue(violation.unit, value);

const toggleExempt = () =>
    router.visit(props.exemptAction, {
        data: { usage_exempt: !props.exempt },
        preserveScroll: true,
    });
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border p-4"
        data-test="server-usage"
    >
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="font-medium">
                    {{ $t('Traffic and usage limits') }}
                </h3>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t('This period: :start – :end', {
                            start: formatDateTime(traffic.period_start),
                            end: formatDateTime(traffic.period_end),
                        })
                    }}
                </p>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <Checkbox
                    :model-value="exempt"
                    data-test="usage-exempt"
                    @update:model-value="toggleExempt"
                />
                {{ $t('Exempt from usage limits') }}
            </label>
        </div>

        <div class="grid gap-1 text-sm">
            <div class="flex flex-wrap justify-between gap-2">
                <span class="font-medium">
                    {{ formatTraffic(traffic.counted_bytes) }}
                    <span class="text-muted-foreground">
                        / {{ formatTraffic(traffic.quota_bytes) }}
                    </span>
                </span>
                <span class="text-xs text-muted-foreground">
                    {{
                        $t('In :in · out :out', {
                            in: formatTraffic(traffic.in_bytes),
                            out: formatTraffic(traffic.out_bytes),
                        })
                    }}
                </span>
            </div>
            <div
                v-if="percent !== null"
                class="h-2 overflow-hidden rounded-full bg-muted"
            >
                <div
                    class="h-full rounded-full"
                    :class="barClass"
                    :style="{ width: `${percent}%` }"
                />
            </div>
        </div>

        <p v-if="exempt" class="text-xs text-muted-foreground">
            {{
                $t(
                    'Going over its quota or a limit is recorded, but the server is never stopped or suspended for it.',
                )
            }}
        </p>

        <ul
            v-if="usage.violations.length > 0"
            class="grid gap-2 text-sm"
            data-test="usage-violations"
        >
            <li
                v-for="violation in usage.violations"
                :key="violation.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
            >
                <span class="grid">
                    <span class="font-medium">
                        {{ $t(violation.label) }}:
                        {{ amount(violation, violation.value) }}
                        <span class="text-muted-foreground">
                            / {{ amount(violation, violation.threshold) }}
                        </span>
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ formatDateTime(violation.started_at) }}
                        <template v-if="violation.window_minutes">
                            ·
                            {{
                                $t('average over :minutes minutes', {
                                    minutes: String(violation.window_minutes),
                                })
                            }}
                        </template>
                    </span>
                </span>
                <span class="flex gap-1">
                    <StatusBadge
                        v-if="violation.resolved_at === null"
                        tone="danger"
                        label="Over"
                    />
                    <StatusBadge v-else tone="neutral" label="Ended" />
                    <StatusBadge
                        :tone="violation.acted_at ? 'warning' : 'neutral'"
                        :label="
                            violation.acted_at
                                ? actions[violation.action]
                                : 'Recorded only'
                        "
                    />
                </span>
            </li>
        </ul>
    </section>
</template>
