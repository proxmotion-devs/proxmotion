<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useDateTime } from '@/composables/useDateTime';
import { show } from '@/routes/admin/guest-script-logs';
import { show as showServer } from '@/routes/admin/servers';
import type { GuestScriptRun, GuestScriptRunDetail } from '@/types/admin';

/**
 * The latest runs of one guest script on the template's servers, trials and
 * operations alike (docs/adr/0023). Details opens what the script got
 * (script, inputs) and printed (stdout, stderr, exit code).
 */
defineProps<{ runs: GuestScriptRun[] }>();

const { formatDateTime } = useDateTime();

// English keys, translated in the template.
const statusLabels: Record<GuestScriptRun['status'], string> = {
    running: 'Running',
    succeeded: 'Succeeded',
    failed: 'Failed',
};
const statusTones = {
    running: 'neutral',
    succeeded: 'success',
    failed: 'danger',
} as const;
const sourceLabels: Record<GuestScriptRun['source'], string> = {
    default: 'Default',
    published: 'Published',
    draft: 'Draft',
};

const open = ref(false);
const detail = ref<GuestScriptRunDetail | null>(null);
const loading = ref(false);
const failed = ref(false);

const details = async (run: GuestScriptRun) => {
    open.value = true;
    detail.value = null;
    failed.value = false;
    loading.value = true;

    try {
        const response = await fetch(show.url(run.uuid), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        detail.value = (await response.json()) as GuestScriptRunDetail;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
};

const duration = (run: GuestScriptRun): string | null => {
    if (!run.started_at || !run.finished_at) {
        return null;
    }

    const seconds = Math.max(
        0,
        Math.round(
            (Date.parse(run.finished_at) - Date.parse(run.started_at)) / 1000,
        ),
    );

    return seconds < 60
        ? `${seconds} s`
        : `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
};

const inputsText = computed(() =>
    detail.value ? JSON.stringify(detail.value.inputs, null, 2) : '',
);
</script>

<template>
    <div class="grid gap-2">
        <h3 class="text-sm font-medium">{{ $t('Recent runs') }}</h3>
        <p v-if="!runs.length" class="text-sm text-muted-foreground">
            {{
                $t(
                    'Not run yet. Runs during operations and trial runs are listed here with their output.',
                )
            }}
        </p>
        <ul v-else class="divide-y rounded-lg border text-sm">
            <li
                v-for="run in runs"
                :key="run.uuid"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                data-test="guest-script-run"
            >
                <span
                    v-if="run.status === 'running'"
                    class="flex items-center gap-1 text-muted-foreground"
                >
                    <Spinner />
                    {{ $t('Running') }}
                </span>
                <StatusBadge
                    v-else
                    :tone="statusTones[run.status]"
                    :label="statusLabels[run.status]"
                />
                <Link
                    :href="showServer(run.server_uuid)"
                    class="font-medium hover:underline"
                    >{{ run.server }}</Link
                >
                <span class="text-muted-foreground">
                    {{ run.trial ? $t('Trial') : $t('Operation') }} ·
                    {{ $t(sourceLabels[run.source])
                    }}<template v-if="run.trace">
                        · {{ $t('traced') }}</template
                    >
                </span>
                <span class="text-muted-foreground">
                    {{ formatDateTime(run.started_at)
                    }}<template v-if="duration(run)">
                        · {{ duration(run) }}</template
                    ><template v-if="run.exit_code !== null">
                        ·
                        {{
                            $t('exit code :code', {
                                code: String(run.exit_code),
                            })
                        }}</template
                    >
                </span>
                <span
                    v-if="run.message"
                    class="basis-full text-red-600 dark:text-red-400"
                    >{{ run.message }}</span
                >
                <Button
                    variant="ghost"
                    size="sm"
                    class="ml-auto"
                    @click="details(run)"
                >
                    <FileText class="h-4 w-4" />
                    {{ $t('Details') }}
                </Button>
            </li>
        </ul>

        <Dialog v-model:open="open">
            <DialogContent
                class="flex max-h-[90vh] flex-col gap-4 overflow-y-auto sm:max-w-4xl"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        detail?.server ?? $t('Script run')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'What the script got and printed inside the server.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <p v-if="loading" class="flex items-center gap-2 text-sm">
                    <Spinner /> {{ $t('Loading…') }}
                </p>
                <p
                    v-else-if="failed"
                    class="text-sm text-red-600 dark:text-red-400"
                    role="alert"
                >
                    {{ $t('The run could not be loaded.') }}
                </p>
                <template v-else-if="detail">
                    <p class="text-sm">
                        <StatusBadge
                            :tone="statusTones[detail.status]"
                            :label="statusLabels[detail.status]"
                        />
                        <span
                            v-if="detail.exit_code !== null"
                            class="ml-2 text-muted-foreground"
                            >{{
                                $t('exit code :code', {
                                    code: String(detail.exit_code),
                                })
                            }}</span
                        >
                        <span
                            v-if="detail.message"
                            class="ml-2 text-red-600 dark:text-red-400"
                            >{{ detail.message }}</span
                        >
                    </p>

                    <template v-if="detail.output_kept">
                        <p
                            v-if="detail.output_truncated"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                $t('The output was long: only its end is kept.')
                            }}
                        </p>
                        <section class="grid gap-1">
                            <h4 class="text-xs font-medium">
                                {{ $t('Standard output') }}
                            </h4>
                            <pre
                                class="max-h-72 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs break-words whitespace-pre-wrap"
                                data-test="guest-script-stdout"
                                >{{ detail.stdout || $t('(empty)') }}</pre>
                        </section>
                        <section class="grid gap-1">
                            <h4 class="text-xs font-medium">
                                {{ $t('Error output') }}
                            </h4>
                            <pre
                                class="max-h-72 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs break-words whitespace-pre-wrap"
                                data-test="guest-script-stderr"
                                >{{ detail.stderr || $t('(empty)') }}</pre>
                        </section>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'The output of password scripts is never kept: it may contain the password.',
                            )
                        }}
                    </p>

                    <details class="text-sm">
                        <summary class="cursor-pointer font-medium">
                            {{ $t('Inputs') }}
                        </summary>
                        <pre
                            class="mt-1 max-h-60 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs"
                            >{{ inputsText }}</pre>
                    </details>
                    <details class="text-sm">
                        <summary class="cursor-pointer font-medium">
                            {{ $t('Script that ran') }}
                        </summary>
                        <pre
                            class="mt-1 max-h-72 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs"
                            >{{ detail.script }}</pre>
                    </details>
                </template>
            </DialogContent>
        </Dialog>
    </div>
</template>
