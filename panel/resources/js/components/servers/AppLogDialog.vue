<script setup lang="ts">
import { Download } from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { show } from '@/routes/admin/app-logs';
import type { AppLogChunk } from '@/types/admin';

/**
 * What an app script printed inside the server (docs/adr/0021). While the
 * script runs, the dialog asks for the new output every few seconds and
 * keeps the view at the end unless the administrator scrolled up.
 */
const props = defineProps<{
    heading: string;
    /** The logs to choose from (install attempts), newest first. */
    logs: { value: string; label: string }[];
}>();

const open = defineModel<boolean>('open', { required: true });

const POLL_MS = 2000;

const selected = ref<string | null>(null);
const text = ref('');
const cursor = ref<number | null>(null);
const live = ref(false);
const step = ref<string | null>(null);
const progress = ref(0);
const loading = ref(false);
const failed = ref(false);
const view = ref<HTMLElement | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;
// Answers for a log that is no longer shown are dropped.
let generation = 0;

const stop = () => {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
};

const atEnd = () =>
    view.value === null ||
    view.value.scrollHeight - view.value.scrollTop - view.value.clientHeight <
        40;

const load = async () => {
    const log = selected.value;

    if (log === null) {
        return;
    }

    const current = generation;
    const follow = atEnd();
    loading.value = true;

    try {
        const response = await fetch(
            show.url(log, {
                query: cursor.value === null ? {} : { cursor: cursor.value },
            }),
            { headers: { Accept: 'application/json' } },
        );

        if (current !== generation) {
            return;
        }

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const chunk = (await response.json()) as AppLogChunk;
        text.value = chunk.reset ? chunk.text : text.value + chunk.text;
        cursor.value = chunk.cursor;
        live.value = chunk.live;
        step.value = chunk.step;
        progress.value = chunk.progress;
        failed.value = false;

        if (follow) {
            await nextTick();
            view.value?.scrollTo({ top: view.value.scrollHeight });
        }
    } catch {
        if (current === generation) {
            failed.value = true;
        }
    } finally {
        if (current === generation) {
            loading.value = false;

            if (open.value && (live.value || failed.value)) {
                timer = setTimeout(load, POLL_MS);
            }
        }
    }
};

const restart = () => {
    stop();
    generation++;
    text.value = '';
    cursor.value = null;
    live.value = false;
    failed.value = false;
    void load();
};

watch(open, (isOpen) => {
    if (isOpen) {
        selected.value = props.logs[0]?.value ?? null;
        restart();
    } else {
        stop();
        generation++;
    }
});

watch(selected, (value, previous) => {
    if (open.value && previous !== null && value !== previous) {
        restart();
    }
});

onBeforeUnmount(stop);

const download = () => {
    const url = URL.createObjectURL(
        new Blob([text.value], { type: 'text/plain;charset=utf-8' }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = `${props.heading.replace(/[^\w.-]+/g, '-')}.log`;
    link.click();
    URL.revokeObjectURL(url);
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[90vh] flex-col gap-4 sm:max-w-4xl">
            <DialogHeader>
                <DialogTitle>{{ heading }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'What the script printed inside the server. Passwords the app was given or reported are masked.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-wrap items-center gap-3 text-sm">
                <NativeSelect
                    v-if="logs.length > 1"
                    id="app_log"
                    v-model="selected"
                    class="w-auto"
                    :options="logs"
                />
                <span
                    v-if="live"
                    class="flex items-center gap-2 text-muted-foreground"
                    data-test="app-log-live"
                >
                    <Spinner />
                    {{ $t('Live') }} · {{ progress }}%<template v-if="step">
                        · {{ step }}</template
                    >
                </span>
                <span
                    v-if="failed"
                    class="text-red-600 dark:text-red-400"
                    role="alert"
                >
                    {{ $t('The log could not be loaded. Trying again…') }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    class="ml-auto"
                    :disabled="text === ''"
                    @click="download"
                >
                    <Download class="h-4 w-4" />
                    {{ $t('Download') }}
                </Button>
            </div>

            <pre
                ref="view"
                class="min-h-64 flex-1 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap"
                data-test="app-log-text"
                >{{
                    text ||
                    (loading
                        ? $t('Loading…')
                        : $t('Nothing has been printed yet.'))
                }}</pre>
        </DialogContent>
    </Dialog>
</template>
