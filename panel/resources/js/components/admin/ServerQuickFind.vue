<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { index as servers } from '@/routes/admin/servers';

/**
 * Finds a server from any page by what a ticket names: hostname, IP
 * address, billing service or customer ID, or VMID (the server list's
 * search). Ctrl+K or ⌘K focuses it; on phones a search button opens the
 * same box in a sheet from the top, with the keyboard up at once.
 */
const query = ref('');
const input = ref<HTMLInputElement | null>(null);
const phoneInput = ref<HTMLInputElement | null>(null);
const phoneOpen = ref(false);

const isMac = computed(
    () =>
        typeof navigator !== 'undefined' &&
        /Mac|iPhone|iPad/.test(navigator.platform),
);

const find = () => {
    const search = query.value.trim();

    phoneOpen.value = false;
    router.visit(servers(search === '' ? {} : { query: { search } }));
};

// The sheet focuses its search box rather than its first control.
const focusPhoneInput = (event: Event) => {
    event.preventDefault();
    phoneInput.value?.focus();
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
            class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 rounded border bg-muted px-1.5 font-sans text-xs text-muted-foreground"
            aria-hidden="true"
            >{{ isMac ? '⌘K' : 'Ctrl K' }}</kbd
        >
    </form>

    <Sheet v-model:open="phoneOpen">
        <SheetTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="sm:hidden"
                :aria-label="$t('Find a server')"
                data-test="server-quick-find-phone"
            >
                <Search class="size-4" />
            </Button>
        </SheetTrigger>
        <SheetContent side="top" class="p-4" @open-auto-focus="focusPhoneInput">
            <SheetHeader class="p-0 pr-8">
                <SheetTitle>{{ $t('Find a server') }}</SheetTitle>
                <SheetDescription>
                    {{ $t('Hostname, IP address, billing ID or VMID.') }}
                </SheetDescription>
            </SheetHeader>
            <form role="search" class="flex gap-2" @submit.prevent="find">
                <input
                    ref="phoneInput"
                    v-model="query"
                    type="search"
                    enterkeyhint="search"
                    class="h-10 min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    :placeholder="$t('Find a server')"
                    :aria-label="
                        $t(
                            'Find a server by hostname, IP address, billing ID or VMID',
                        )
                    "
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    data-test="server-quick-find-phone-input"
                />
                <Button type="submit" size="lg">
                    {{ $t('Search') }}
                </Button>
            </form>
        </SheetContent>
    </Sheet>
</template>
