<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavGroup } from '@/composables/useMainNavigation';

defineProps<{
    groups: NavGroup[];
}>();

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

// A collapsed (icon only) sidebar has no room for sub-pages: the item then
// links to its own page, which opens the first of them.
const { state, isMobile } = useSidebar();
</script>

<template>
    <SidebarGroup
        v-for="group in groups"
        :key="group.title"
        class="px-2 py-0"
        :data-test="`nav-section-${group.title}`"
    >
        <SidebarGroupLabel>{{ $t(group.title) }}</SidebarGroupLabel>
        <SidebarMenu>
            <template v-for="item in group.items" :key="item.title">
                <Collapsible
                    v-if="
                        item.items?.length && (state === 'expanded' || isMobile)
                    "
                    as-child
                    :default-open="isCurrentOrParentUrl(item.href)"
                    class="group/collapsible"
                >
                    <SidebarMenuItem :data-test="`nav-group-${item.title}`">
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton
                                :is-active="isCurrentOrParentUrl(item.href)"
                                :tooltip="$t(item.title)"
                            >
                                <component :is="item.icon" />
                                <span>{{ $t(item.title) }}</span>
                                <ChevronRight
                                    class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub>
                                <SidebarMenuSubItem
                                    v-for="child in item.items"
                                    :key="child.title"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="isCurrentUrl(child.href)"
                                    >
                                        <Link :href="child.href">
                                            <span>{{ $t(child.title) }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>
                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        :is-active="
                            item.items?.length
                                ? isCurrentOrParentUrl(item.href)
                                : isCurrentUrl(item.href)
                        "
                        :tooltip="$t(item.title)"
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" />
                            <span>{{ $t(item.title) }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
