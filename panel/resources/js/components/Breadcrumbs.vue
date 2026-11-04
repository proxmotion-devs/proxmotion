<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type Props = {
    breadcrumbs: BreadcrumbItemType[];
};

defineProps<Props>();
</script>

<template>
    <Breadcrumb>
        <BreadcrumbList>
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <!-- Names such as hostnames can be long: shortened, in full on hover. -->
                <BreadcrumbItem class="min-w-0">
                    <template v-if="index === breadcrumbs.length - 1">
                        <BreadcrumbPage
                            class="block max-w-48 truncate sm:max-w-80"
                            :title="$t(item.title)"
                            >{{ $t(item.title) }}</BreadcrumbPage
                        >
                    </template>
                    <template v-else>
                        <BreadcrumbLink as-child>
                            <Link
                                :href="item.href"
                                class="block max-w-48 truncate sm:max-w-80"
                                :title="$t(item.title)"
                                >{{ $t(item.title) }}</Link
                            >
                        </BreadcrumbLink>
                    </template>
                </BreadcrumbItem>
                <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
