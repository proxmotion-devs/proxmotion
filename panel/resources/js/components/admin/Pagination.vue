<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import type { Paginated } from '@/types/admin';

/**
 * Pages of a Laravel paginator. Its first and last links are "previous" and
 * "next"; their HTML labels are replaced by our own, so nothing is rendered as
 * HTML.
 */
const props = defineProps<{
    paginator: Pick<Paginated<unknown>, 'links' | 'last_page' | 'current_page'>;
}>();

const previous = computed(() => props.paginator.links[0]);
const next = computed(
    () => props.paginator.links[props.paginator.links.length - 1],
);
const pages = computed(() => props.paginator.links.slice(1, -1));

const item =
    'inline-flex h-8 min-w-8 items-center justify-center gap-1 rounded-md px-2.5 text-sm tabular-nums outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<template>
    <nav
        v-if="paginator.last_page > 1"
        class="flex flex-wrap items-center gap-1"
        :aria-label="$t('Pagination')"
        data-test="pagination"
    >
        <component
            :is="previous?.url ? Link : 'span'"
            :href="previous?.url ?? undefined"
            preserve-scroll
            :class="[
                item,
                previous?.url
                    ? 'border hover:bg-accent'
                    : 'border text-muted-foreground opacity-50',
            ]"
            :aria-disabled="previous?.url ? undefined : 'true'"
        >
            <ChevronLeft class="size-4" aria-hidden="true" />
            {{ $t('Previous') }}
        </component>

        <template v-for="(link, index) in pages" :key="index">
            <Link
                v-if="link.url && !link.active"
                :href="link.url"
                preserve-scroll
                :class="[item, 'hover:bg-accent']"
                :aria-label="$t('Page :page', { page: link.label })"
            >
                {{ link.label }}
            </Link>
            <span
                v-else-if="link.active"
                :class="[
                    item,
                    'bg-primary font-medium text-primary-foreground',
                ]"
                aria-current="page"
            >
                {{ link.label }}
            </span>
            <span v-else :class="[item, 'text-muted-foreground']">
                {{ link.label }}
            </span>
        </template>

        <component
            :is="next?.url ? Link : 'span'"
            :href="next?.url ?? undefined"
            preserve-scroll
            :class="[
                item,
                next?.url
                    ? 'border hover:bg-accent'
                    : 'border text-muted-foreground opacity-50',
            ]"
            :aria-disabled="next?.url ? undefined : 'true'"
        >
            {{ $t('Next') }}
            <ChevronRight class="size-4" aria-hidden="true" />
        </component>
    </nav>
</template>
