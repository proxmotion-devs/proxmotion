<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { index as servers } from '@/routes/admin/servers';

/**
 * Finds a server from any page by what a ticket names: hostname, IP
 * address, billing service or customer ID, or VMID (the server list's
 * search). Ctrl+K or ⌘K focuses it; on phones it is a button to the list.
 */
const query = ref('');
const input = ref<HTMLInputElement | null>(null);

const isMac = computed(
    () =>
        typeof navigator !== 'undefined' &&
        /Mac|iPhone|iPad/.test(navigator.platform),
);

const find = () => {
    const search = query.value.trim();

    router.visit(servers(search === '' ? {} : { query: { search } }));
};

const onKeydown = (event: KeyboardEvent) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        input.value?.focus();
        input.value?.select();
    }
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <form
        role="search"
        class="relative hidden sm:block"
        data-test="server-quick-find"
        @submit.prevent="find"
    >
        <Search
            class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
        <input
            ref="input"
            v-model="query"
            type="search"
            class="h-9 w-64 rounded-md border border-input bg-transparent pr-14 pl-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 lg:w-80 dark:bg-input/30"
            :placeholder="$t('Find a server')"
            :aria-label="
                $t('Find a server by hostname, IP address, billing ID or VMID')
            "
            :aria-keyshortcuts="isMac ? 'Meta+K' : 'Control+K'"
            autocomplete="off"
            spellcheck="false"
        />
        <kbd
            class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 rounded border bg-muted px-1.5 font-sans text-[11px] text-muted-foreground"
            aria-hidden="true"
            >{{ isMac ? '⌘K' : 'Ctrl K' }}</kbd
        >
    </form>
    <Button
        variant="ghost"
        size="icon"
        class="sm:hidden"
        :aria-label="$t('Find a server')"
        as-child
    >
        <Link :href="servers()">
            <Search class="size-4" />
        </Link>
    </Button>
</template>
