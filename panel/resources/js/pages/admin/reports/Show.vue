<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import ReportTable from '@/components/admin/ReportTable.vue';
import Heading from '@/components/Heading.vue';
import { REPORTS } from '@/lib/reports';
import type { ReportKey } from '@/lib/reports';
import { csv, index, show } from '@/routes/admin/reports';
import type {
    ReportFilter,
    ReportTable as ReportTableData,
} from '@/types/admin';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Reports', href: index() }] },
});

const props = defineProps<{
    report: ReportKey;
    filters: Record<string, string | number | null>;
    filterOptions: Record<string, ReportFilter>;
    tables: ReportTableData[];
}>();

const page = computed(
    () => REPORTS.find((report) => report.key === props.report) ?? REPORTS[0],
);

// '' stands for a filter's "all" choice.
const chosen = reactive<Record<string, string | number | null>>(
    Object.fromEntries(
        Object.entries(props.filters).map(([key, value]) => [key, value ?? '']),
    ),
);

const query = computed(() =>
    Object.fromEntries(
        Object.entries(props.filters).filter(
            ([, value]) => value !== null && value !== '',
        ),
    ),
);

const options = (filter: ReportFilter) => [
    ...(filter.all === null ? [] : [{ value: '', label: filter.all }]),
    ...filter.options,
];

watch(chosen, () =>
    router.get(
        show(props.report, {
            query: Object.fromEntries(
                Object.entries(chosen).filter(
                    ([, value]) => value !== null && value !== '',
                ),
            ),
        }),
        {},
        { preserveScroll: true, preserveState: true },
    ),
);
</script>

<template>
    <Head :title="page.title" />

    <div class="flex flex-col gap-6 p-4">
        <Heading :title="page.title" :description="page.description" />

        <nav class="-mt-4 flex flex-wrap gap-1" :aria-label="$t('Reports')">
            <Link
                v-for="item in REPORTS"
                :key="item.key"
                :href="show(item.key)"
                class="rounded-md border px-3 py-1 text-sm"
                :class="{
                    'bg-primary text-primary-foreground': item.key === report,
                }"
            >
                {{ $t(item.title) }}
            </Link>
        </nav>

        <div
            v-if="Object.keys(filterOptions).length"
            class="flex flex-wrap items-end gap-3"
        >
            <label
                v-for="(filter, key) in filterOptions"
                :key="key"
                class="flex flex-col gap-1 text-sm"
            >
                <span class="text-muted-foreground">{{ filter.label }}</span>
                <NativeSelect
                    v-model="chosen[key]"
                    :options="options(filter)"
                    class="w-64"
                />
            </label>
        </div>

        <ReportTable
            v-for="table in tables"
            :key="table.key"
            :table="table"
            :csv-href="csv({ report, table: table.key }, { query }).url"
        />
    </div>
</template>
