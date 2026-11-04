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

        <!-- Links to the other reports, marked like the server page's tabs: a
             foreground underline, never the provider colour; 36px targets. -->
        <nav
            class="-mt-2 flex [scrollbar-width:none] gap-1 overflow-x-auto border-b"
            :aria-label="$t('Reports')"
        >
            <Link
                v-for="item in REPORTS"
                :key="item.key"
                :href="show(item.key)"
                class="-mb-px inline-flex h-9 shrink-0 items-center border-b-2 px-3 text-sm whitespace-nowrap outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
                :class="
                    item.key === report
                        ? 'border-foreground font-medium'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                :aria-current="item.key === report ? 'page' : undefined"
                :data-test="`report-link-${item.key}`"
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
