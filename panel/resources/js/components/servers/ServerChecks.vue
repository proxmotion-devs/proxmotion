<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleMinus, RefreshCw } from '@lucide/vue';
import { ref } from 'vue';
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

// English keys, translated in the template with the check's params.
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
                <h3 class="font-medium">{{ $t('Checks') }}</h3>
                <p class="text-xs text-muted-foreground">
                    {{
                        verification
                            ? $t('Checked inside the server :time.', {
                                  time: formatDateTime(verification.checked_at),
                              })
                            : $t('Not checked yet.')
                    }}
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

        <ul v-if="verification" class="flex flex-col gap-1 text-sm">
            <li
                v-for="(check, index) in verification.checks"
                :key="`${check.key}-${index}`"
                class="flex flex-wrap items-center gap-x-2"
                :data-test="`check-${check.status}`"
            >
                <CircleCheck
                    v-if="check.status === 'passed'"
                    class="h-4 w-4 text-emerald-600"
                />
                <CircleAlert
                    v-else-if="check.status === 'failed'"
                    class="h-4 w-4 text-amber-600"
                />
                <CircleMinus v-else class="h-4 w-4 text-muted-foreground/60" />
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
