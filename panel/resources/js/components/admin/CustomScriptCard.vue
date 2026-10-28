<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Pencil,
    Play,
    Save,
    Trash2,
    Upload,
} from '@lucide/vue';
import { reactive, watch } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import GuestScriptRuns from '@/components/admin/GuestScriptRuns.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import CodeEditor from '@/components/apps/CodeEditor.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    destroy,
    discard,
    draft as saveDraft,
    move,
    publish,
    run,
} from '@/routes/admin/os-templates/custom-scripts';
import type {
    CustomScript,
    GuestScriptEvent,
    GuestScriptRun,
    OsPlatform,
    Option,
} from '@/types/admin';

/**
 * One administrator's script of an OS template (docs/adr/0043): its triggers,
 * the editor with draft and publish (docs/adr/0023), trial runs on a server
 * of the template and the latest runs.
 */
const props = defineProps<{
    template: { uuid: string; platform: OsPlatform };
    script: CustomScript;
    runs: GuestScriptRun[];
    servers: Option[];
    eventTitles: Record<GuestScriptEvent, string>;
    canTrace: boolean;
    maxBytes: number;
    first: boolean;
    last: boolean;
}>();

const emit = defineEmits<{
    edit: [script: CustomScript];
    started: [after: string | null];
}>();

const route = [props.template.uuid, props.script.uuid] as [string, string];

// The editor shows the draft when there is one, otherwise what runs now.
const shown = () => props.script.draft ?? props.script.content ?? '';
const form = useForm({ script: shown() });

// After a reload the editor follows what was saved, published or discarded.
watch(
    () => [props.script.draft, props.script.content],
    () => {
        form.defaults({ script: shown() });
        form.reset();
    },
);

const tryOn = reactive({
    server: props.servers[0]?.value ?? null,
    trace: false,
});

const visit = (action: Parameters<typeof router.visit>[0]) =>
    router.visit(action, { preserveScroll: true });

const moveTo = (direction: 'up' | 'down') =>
    router.visit(move(route), { data: { direction }, preserveScroll: true });

const tryScript = () => {
    const after = props.runs[0]?.uuid ?? null;

    router.visit(run(route), {
        data: {
            server: tryOn.server,
            draft: props.script.draft !== null,
            trace: props.canTrace && tryOn.trace,
        },
        preserveScroll: true,
        onSuccess: () => emit('started', after),
    });
};

const bytes = (text: string) => new TextEncoder().encode(text).length;
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border p-4"
        data-test="custom-script"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="grid gap-1">
                <h3 class="flex flex-wrap items-center gap-2 font-medium">
                    {{ script.name }}
                    <StatusBadge
                        v-if="script.content === null"
                        tone="neutral"
                        label="Not published"
                    />
                    <StatusBadge
                        v-if="script.draft !== null"
                        tone="warning"
                        label="Draft"
                    />
                </h3>
                <p class="flex flex-wrap gap-1 text-xs text-muted-foreground">
                    <span v-if="!script.manual && !script.events.length">{{
                        $t('No trigger: it only runs in trials.')
                    }}</span>
                    <span
                        v-if="script.manual"
                        class="rounded-md border px-1.5 py-0.5"
                        >{{ $t('Run by hand') }}</span
                    >
                    <span
                        v-for="event in script.events"
                        :key="event"
                        class="rounded-md border px-1.5 py-0.5"
                        >{{
                            $t('After: :event', {
                                event: $t(eventTitles[event]),
                            })
                        }}</span
                    >
                </p>
            </div>
            <div class="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    :disabled="first"
                    :aria-label="$t('Move up')"
                    :title="$t('Move up')"
                    @click="moveTo('up')"
                >
                    <ArrowUp class="h-4 w-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    :disabled="last"
                    :aria-label="$t('Move down')"
                    :title="$t('Move down')"
                    @click="moveTo('down')"
                >
                    <ArrowDown class="h-4 w-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    :aria-label="$t('Edit name and triggers')"
                    :title="$t('Edit name and triggers')"
                    @click="emit('edit', script)"
                >
                    <Pencil class="h-4 w-4" />
                </Button>
                <ConfirmDelete
                    :action="destroy(route)"
                    :name="script.name"
                    description="It no longer runs on any server. Its earlier runs stay in the logs."
                />
            </div>
        </div>

        <CodeEditor
            v-model="form.script"
            :language="template.platform === 'windows' ? 'powershell' : 'shell'"
            :aria-label="script.name"
            :placeholder="
                template.platform === 'windows' ? '# PowerShell' : '#!/bin/sh'
            "
        />
        <p
            v-if="form.errors.script"
            class="text-sm text-red-600 dark:text-red-500"
        >
            {{ form.errors.script }}
        </p>
        <p
            v-if="script.draft !== null"
            class="text-sm text-amber-700 dark:text-amber-400"
        >
            {{
                script.content === null
                    ? $t(
                          'Nothing runs this script until you publish the draft; trial runs use the draft.',
                      )
                    : $t(
                          'You are editing a draft. Operations keep running the published script until you publish the draft; trial runs use the draft.',
                      )
            }}
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                :disabled="form.processing || !form.isDirty"
                @click="form.submit(saveDraft(route), { preserveScroll: true })"
            >
                <Save class="h-4 w-4" />
                {{ $t('Save draft') }}
            </Button>
            <Button
                v-if="script.draft !== null"
                variant="outline"
                :disabled="form.isDirty"
                :title="form.isDirty ? $t('Save the script first.') : undefined"
                @click="visit(publish(route))"
            >
                <Upload class="h-4 w-4" />
                {{ $t('Publish') }}
            </Button>
            <Button
                v-if="script.draft !== null && script.content !== null"
                variant="outline"
                @click="visit(discard(route))"
            >
                <Trash2 class="h-4 w-4" />
                {{ $t('Discard draft') }}
            </Button>
            <span class="text-xs text-muted-foreground">
                {{
                    $t(':used of :max bytes', {
                        used: String(bytes(form.script)),
                        max: String(maxBytes),
                    })
                }}
            </span>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <template v-if="servers.length">
                    <label
                        v-if="canTrace"
                        class="flex items-center gap-2 text-sm"
                        :title="
                            $t(
                                'Runs the script with sh -x: every command is printed to the error output before it runs.',
                            )
                        "
                    >
                        <Checkbox v-model="tryOn.trace" />
                        {{ $t('Trace commands') }}
                    </label>
                    <NativeSelect
                        v-model="tryOn.server"
                        :options="servers"
                        :aria-label="$t('Server')"
                        class="w-56"
                    />
                    <Button
                        variant="outline"
                        :disabled="!tryOn.server || form.isDirty"
                        :title="
                            form.isDirty
                                ? $t('Save the script first.')
                                : undefined
                        "
                        @click="tryScript"
                    >
                        <Play class="h-4 w-4" />
                        {{
                            script.draft !== null
                                ? $t('Run draft on server')
                                : $t('Run on server')
                        }}
                    </Button>
                </template>
                <span v-else class="text-xs text-muted-foreground">{{
                    $t('No active server uses this template.')
                }}</span>
            </div>
        </div>

        <GuestScriptRuns :runs="runs" />
    </section>
</template>
