<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronDown,
    CircleAlert,
    CircleCheck,
    CircleMinus,
    RefreshCw,
} from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useDateTime } from '@/composables/useDateTime';
import type { ServerVerification } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * The latest checks from inside the server after a create, reinstall or
 * Check again (docs/adr/0032 §6). A failed check only warns: the server
 * runs, but something the panel set up did not arrive as expected.
 */
const props = defineProps<{
    verification: ServerVerification | null;
    /** Check again; omitted when the server cannot be checked now. */
    action?: RouteDefinition<'post'>;
    disabled?: boolean;
}>();

const { formatDateTime } = useDateTime();
const processing = ref(false);

// Everything fine: one line, the list behind a toggle. Any failed check shows the list.
const failed = computed(
    () =>
        props.verification?.checks.filter((check) => check.status === 'failed')
            .length ?? 0,
);
const expanded = ref(false);
const showList = computed(() => failed.value > 0 || expanded.value);
const listId = useId();

// English keys, translated in the template with the check's params.
const statuses: Record<string, string> = {
    passed: 'Passed',
    failed: 'Failed',
    skipped: 'Skipped',
};

const labels: Record<string, string> = {
    addresses: 'IP addresses on the network interface',
    gateway: 'Gateway :address answers',
    dns: 'DNS resolves :name',
    hostname: 'Hostname is :hostname',
    disk: 'Root file system has :size',
};

const checkAgain = () => {
    if (!props.action) {
        return;
    }

    router.visit(props.action, {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    });
};
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border p-4"
        data-test="server-checks"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="font-medium">{{ $t('Checks') }}</h2>
                <!-- Announced when Check again brings a new result. -->
                <p
                    class="text-xs text-muted-foreground"
                    aria-live="polite"
                    data-test="checks-time"
                >
                    {{
                        verification
                            ? $t('Checked inside the server :time.', {
                                  time: formatDateTime(verification.checked_at),
                              })
                            : $t('Not checked yet.')
                    }}
                    <span v-if="verification" class="sr-only">{{
                        failed > 0
                            ? $t(':count of the checks failed.', {
                                  count: String(failed),
                              })
                            : $t('Nothing failed inside the server.')
                    }}</span>
                </p>
            </div>
            <Button
                v-if="action"
                variant="outline"
                size="sm"
                :disabled="disabled || processing"
                data-test="server-check-again"
                @click="checkAgain"
            >
                <Spinner v-if="processing" />
                <RefreshCw v-else class="h-4 w-4" />
                {{ $t('Check again') }}
            </Button>
        </div>

        <div
            v-if="verification && failed === 0"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
            data-test="checks-summary"
        >
            <span class="flex items-center gap-2">
                <CircleCheck
                    class="h-4 w-4 text-status-success-foreground"
                    aria-hidden="true"
                />
                {{ $t('Nothing failed inside the server.') }}
            </span>
            <button
                type="button"
                class="-mx-2 inline-flex h-8 items-center gap-1 rounded-md px-2 text-xs text-muted-foreground outline-none hover:bg-muted hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                :aria-expanded="expanded"
                :aria-controls="listId"
                data-test="checks-toggle"
                @click="expanded = !expanded"
            >
                {{ expanded ? $t('Hide checks') : $t('Show checks') }}
                <ChevronDown
                    class="h-3.5 w-3.5 transition-transform"
                    :class="{ 'rotate-180': expanded }"
                    aria-hidden="true"
                />
            </button>
        </div>

        <ul
            v-if="verification && showList"
            :id="listId"
            class="flex flex-col gap-1 text-sm"
        >
            <li
                v-for="(check, index) in verification.checks"
                :key="`${check.key}-${index}`"
                class="flex flex-wrap items-center gap-x-2"
                :data-test="`check-${check.status}`"
            >
                <CircleCheck
                    v-if="check.status === 'passed'"
                    class="h-4 w-4 text-status-success-foreground"
                    aria-hidden="true"
                />
                <CircleAlert
                    v-else-if="check.status === 'failed'"
                    class="h-4 w-4 text-status-warning-text"
                    aria-hidden="true"
                />
                <CircleMinus
                    v-else
                    class="h-4 w-4 text-muted-foreground/60"
                    aria-hidden="true"
                />
                <span class="sr-only">{{
                    `${$t(statuses[check.status] ?? check.status)}: `
                }}</span>
                <span
                    :class="{
                        'text-muted-foreground': check.status === 'skipped',
                    }"
                    >{{
                        $t(labels[check.key] ?? check.key, check.params)
                    }}</span
                >
                <span
                    v-if="check.detail"
                    class="text-xs text-muted-foreground"
                    >{{ $t(check.detail, check.params) }}</span
                >
            </li>
        </ul>
    </section>
</template>
