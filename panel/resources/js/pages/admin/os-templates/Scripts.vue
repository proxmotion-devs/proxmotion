<script setup lang="ts">
import {
    Head,
    router,
    setLayoutProps,
    useForm,
    usePoll,
} from '@inertiajs/vue3';
import { Play, RotateCcw, Save, Trash2, Upload } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref, watch } from 'vue';
import GuestScriptRuns from '@/components/admin/GuestScriptRuns.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import CodeEditor from '@/components/apps/CodeEditor.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    destroy,
    discard,
    publish,
    run,
    update,
} from '@/routes/admin/os-templates/scripts';
import { index, scripts as scriptsPage } from '@/routes/admin/os-templates';
import type {
    GuestScriptEvent,
    GuestScriptRun,
    GuestScriptSlot,
    OsPlatform,
    Option,
} from '@/types/admin';

const props = defineProps<{
    template: { uuid: string; name: string; platform: OsPlatform };
    scripts: GuestScriptSlot[];
    logs: Record<GuestScriptEvent, GuestScriptRun[]>;
    servers: Option[];
    maxBytes: number;
    canTrace: boolean;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'OS templates', href: index() },
        { title: props.template.name, href: scriptsPage(props.template.uuid) },
    ],
});

// English keys, translated in the template.
const eventTitles: Record<GuestScriptEvent, string> = {
    provisioned: 'After installation',
    addresses_changed: 'Additional IP addresses',
    disk_resized: 'Disk grown',
    password_reset: 'Password reset',
    hostname_changed: 'Hostname changed',
};
const eventDescriptions: Record<GuestScriptEvent, string> = {
    provisioned:
        'Runs once after the server was created or reinstalled, after its additional addresses and before the checks from inside; the server becomes active only afterwards. The reason input says create, reinstall or trial. There is no default script.',
    addresses_changed:
        'Configures the additional IP addresses after creation, reinstall and every resource change; also removes released ones.',
    disk_resized:
        'Grows the partition and file system right after an upgrade enlarged the disk.',
    hostname_changed:
        'Renames the system when the hostname is changed. On Windows the new name takes effect at the next restart.',
    password_reset:
        'Replaces the guest agent setting the password. The new password arrives on standard input; if the script fails, the reset fails and no new password is shown.',
};

// When the scripts run (CreateServerPipeline::BUILD_STAGES, docs/adr/0010).
// Nothing runs before the first boot: the template is cloned, not installed.
type Stage = { title: string; detail: string; event?: GuestScriptEvent };

const installStages: Stage[] = [
    {
        title: 'Clone and first boot',
        detail: 'No script: cloud-init (Cloudbase-Init on Windows) sets the user, password, SSH keys, network and hostname.',
    },
    {
        title: 'Guest agent',
        detail: 'Waits for the guest agent, then for cloud-init (Linux) or Windows setup to finish.',
    },
    {
        title: 'Additional IP addresses',
        detail: 'Only when the server has additional addresses.',
        event: 'addresses_changed',
    },
    {
        title: 'After installation',
        detail: 'Only when this template has a script.',
        event: 'provisioned',
    },
    {
        title: 'Checks from inside',
        detail: 'Addresses, gateways, DNS, hostname and disk; a failed check only warns.',
    },
    {
        title: 'Server active',
        detail: 'An app chosen with the order or the reinstall starts now.',
    },
];
const laterStages: Stage[] = [
    {
        title: 'Additional IP addresses',
        detail: 'After address edits, resource changes and moves to another location.',
        event: 'addresses_changed',
    },
    {
        title: 'Disk grown',
        detail: 'After an upgrade enlarged the disk.',
        event: 'disk_resized',
    },
    {
        title: 'Hostname changed',
        detail: 'When the server is renamed.',
        event: 'hostname_changed',
    },
    {
        title: 'Password reset',
        detail: 'When the root or Administrator password is reset.',
        event: 'password_reset',
    },
];

const stageGroups = [
    { title: 'Create and reinstall', stages: installStages, numbered: true },
    { title: 'Later changes', stages: laterStages, numbered: false },
];

const timeLimit = (seconds: number) =>
    seconds % 60 === 0 && seconds > 60
        ? trans(':count minutes', { count: String(seconds / 60) })
        : trans(':count seconds', { count: String(seconds) });

// The editor shows the draft when there is one, otherwise what runs now.
const shown = (slot: GuestScriptSlot) =>
    slot.draft ?? slot.content ?? slot.default ?? '';

const forms = Object.fromEntries(
    props.scripts.map((slot) => [slot.event, useForm({ script: shown(slot) })]),
) as Record<GuestScriptEvent, ReturnType<typeof useForm<{ script: string }>>>;

const tryOn = reactive(
    Object.fromEntries(
        props.scripts.map((slot) => [
            slot.event,
            props.servers[0]?.value ?? null,
        ]),
    ) as Record<GuestScriptEvent, string | null>,
);
const trace = reactive(
    Object.fromEntries(
        props.scripts.map((slot) => [slot.event, false]),
    ) as Record<GuestScriptEvent, boolean>,
);

// After a reload the editor follows what was saved, published or discarded.
const resync = (event: GuestScriptEvent) => {
    const slot = props.scripts.find((item) => item.event === event);

    if (slot) {
        forms[event].defaults({ script: shown(slot) });
        forms[event].reset();
    }
};

const save = (event: GuestScriptEvent) => {
    forms[event].submit(update([props.template.uuid, event]), {
        preserveScroll: true,
        onSuccess: () => resync(event),
    });
};

const visit = (
    route: Parameters<typeof router.visit>[0],
    event: GuestScriptEvent,
) => {
    router.visit(route, {
        preserveScroll: true,
        onSuccess: () => resync(event),
    });
};

// Trials are queued: follow the runs until a new one has shown up and
// finished (or none started within a minute, e.g. no queue worker).
const awaiting = ref<{
    event: GuestScriptEvent;
    after: string | null;
    since: number;
} | null>(null);
const running = computed(() =>
    Object.values(props.logs).some((runs) =>
        runs.some((item) => item.status === 'running'),
    ),
);
const { start: startPolling, stop: stopPolling } = usePoll(
    2000,
    { only: ['logs'] },
    { autoStart: false },
);

watch(
    () => awaiting.value !== null || running.value,
    (value) => (value ? startPolling() : stopPolling()),
    { immediate: true },
);
watch(
    () => props.logs,
    (logs) => {
        const wait = awaiting.value;

        if (wait === null) {
            return;
        }

        const newest = logs[wait.event]?.[0];
        const arrived =
            newest !== undefined &&
            newest.uuid !== wait.after &&
            newest.status !== 'running';

        if (arrived || Date.now() - wait.since > 60000) {
            awaiting.value = null;
        }
    },
);

const tryScript = (slot: GuestScriptSlot) => {
    const after = props.logs[slot.event]?.[0]?.uuid ?? null;

    router.visit(run([props.template.uuid, slot.event]), {
        data: {
            server: tryOn[slot.event],
            draft: slot.draft !== null,
            trace: props.canTrace && trace[slot.event],
        },
        preserveScroll: true,
        onSuccess: () => {
            const newest = props.logs[slot.event]?.[0];

            // With a synchronous queue the run has finished already.
            if (
                newest === undefined ||
                newest.uuid === after ||
                newest.status === 'running'
            ) {
                awaiting.value = {
                    event: slot.event,
                    after,
                    since: Date.now(),
                };
            }
        },
    });
};

const bytes = (text: string) => new TextEncoder().encode(text).length;
</script>

<template>
    <Head title="Scripts" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="template.name"
            description="Scripts that run inside this template's servers through the guest agent, as root (Linux) or SYSTEM (Windows). Inputs are written next to the script as JSON (and key=value lines on Linux); they are never inserted into the script."
        />
        <p class="-mt-4 text-sm text-muted-foreground">
            {{ $t('Platform') }}:
            <span class="font-medium">{{
                template.platform === 'windows' ? 'Windows' : 'Linux'
            }}</span>
        </p>

        <section class="grid gap-4 rounded-xl border p-4">
            <div class="grid gap-1">
                <h2 class="font-medium">{{ $t('When the scripts run') }}</h2>
                <p class="max-w-3xl text-sm text-muted-foreground">
                    {{
                        $t(
                            'The template is cloned, not installed: nothing runs before the first boot. Settings every server needs from its first boot belong in the template itself.',
                        )
                    }}
                </p>
            </div>
            <div class="grid gap-6 lg:grid-cols-2">
                <div
                    v-for="group in stageGroups"
                    :key="group.title"
                    class="grid content-start gap-2"
                >
                    <h3 class="text-sm font-medium">
                        {{ $t(group.title) }}
                    </h3>
                    <ol class="grid gap-2">
                        <li
                            v-for="(stage, index) in group.stages"
                            :key="stage.title"
                            class="flex gap-3 text-sm"
                        >
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs"
                                :class="
                                    stage.event
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'text-muted-foreground'
                                "
                                aria-hidden="true"
                                >{{ group.numbered ? index + 1 : '•' }}</span
                            >
                            <span class="grid gap-0.5">
                                <a
                                    v-if="stage.event"
                                    :href="`#script-${stage.event}`"
                                    class="font-medium underline-offset-4 hover:underline"
                                    >{{ $t(stage.title) }}</a
                                >
                                <span v-else class="font-medium">{{
                                    $t(stage.title)
                                }}</span>
                                <span class="text-muted-foreground">{{
                                    $t(stage.detail)
                                }}</span>
                            </span>
                        </li>
                    </ol>
                </div>
            </div>
        </section>

        <section
            v-for="slot in scripts"
            :id="`script-${slot.event}`"
            :key="slot.event"
            class="flex scroll-mt-4 flex-col gap-3 rounded-xl border p-4"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="grid gap-1">
                    <h2 class="flex items-center gap-2 font-medium">
                        {{ $t(eventTitles[slot.event]) }}
                        <StatusBadge
                            v-if="slot.content !== null"
                            tone="warning"
                            label="Custom"
                        />
                        <StatusBadge
                            v-else-if="slot.default !== null"
                            tone="neutral"
                            label="Default"
                        />
                        <StatusBadge v-else tone="neutral" label="None" />
                        <StatusBadge
                            v-if="slot.draft !== null"
                            tone="warning"
                            label="Draft"
                        />
                    </h2>
                    <p class="max-w-3xl text-sm text-muted-foreground">
                        {{ $t(eventDescriptions[slot.event]) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ $t('Inputs') }}:
                        <code class="font-mono">{{
                            slot.inputs.join(', ')
                        }}</code>
                        · {{ $t('Time limit') }}:
                        {{ timeLimit(slot.timeout_seconds) }}
                    </p>
                    <p
                        v-if="slot.event === 'provisioned'"
                        class="max-w-3xl text-sm text-muted-foreground"
                    >
                        {{
                            template.platform === 'windows'
                                ? $t(
                                      'The task waits for the script. Start longer work (large installs, updates) in the background, for example as a scheduled task, and let the script return.',
                                  )
                                : $t(
                                      'The task waits for the script. Start longer work (large installs, updates) in the background, for example with systemd-run --collect, and let the script return.',
                                  )
                        }}
                    </p>
                </div>
            </div>

            <CodeEditor
                v-model="forms[slot.event].script"
                :language="
                    template.platform === 'windows' ? 'powershell' : 'shell'
                "
                :aria-label="$t(eventTitles[slot.event])"
                :placeholder="
                    template.platform === 'windows'
                        ? '# PowerShell'
                        : '#!/bin/sh'
                "
            />
            <p
                v-if="forms[slot.event].errors.script"
                class="text-sm text-red-600 dark:text-red-500"
            >
                {{ forms[slot.event].errors.script }}
            </p>

            <p
                v-if="slot.draft !== null"
                class="text-sm text-amber-700 dark:text-amber-400"
            >
                {{
                    $t(
                        'You are editing a draft. Operations keep running the published script until you publish the draft; trial runs use the draft.',
                    )
                }}
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    :disabled="
                        forms[slot.event].processing ||
                        !forms[slot.event].isDirty
                    "
                    @click="save(slot.event)"
                >
                    <Save class="h-4 w-4" />
                    {{ $t('Save draft') }}
                </Button>
                <Button
                    v-if="slot.draft !== null"
                    variant="outline"
                    :disabled="forms[slot.event].isDirty"
                    :title="
                        forms[slot.event].isDirty
                            ? $t('Save the script first.')
                            : undefined
                    "
                    @click="
                        visit(publish([template.uuid, slot.event]), slot.event)
                    "
                >
                    <Upload class="h-4 w-4" />
                    {{ $t('Publish') }}
                </Button>
                <Button
                    v-if="slot.draft !== null"
                    variant="outline"
                    @click="
                        visit(discard([template.uuid, slot.event]), slot.event)
                    "
                >
                    <Trash2 class="h-4 w-4" />
                    {{ $t('Discard draft') }}
                </Button>
                <Button
                    v-if="slot.content !== null"
                    variant="outline"
                    @click="
                        visit(destroy([template.uuid, slot.event]), slot.event)
                    "
                >
                    <RotateCcw class="h-4 w-4" />
                    {{ $t('Reset to default') }}
                </Button>
                <span class="text-xs text-muted-foreground">
                    {{
                        $t(':used of :max bytes', {
                            used: String(bytes(forms[slot.event].script)),
                            max: String(maxBytes),
                        })
                    }}
                </span>

                <div
                    v-if="
                        slot.can_be_tried &&
                        (slot.content !== null ||
                            slot.draft !== null ||
                            slot.default !== null)
                    "
                    class="ml-auto flex flex-wrap items-center gap-2"
                >
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
                            <Checkbox v-model="trace[slot.event]" />
                            {{ $t('Trace commands') }}
                        </label>
                        <NativeSelect
                            v-model="tryOn[slot.event]"
                            :options="servers"
                            :aria-label="$t('Server')"
                            class="w-56"
                        />
                        <Button
                            variant="outline"
                            :disabled="
                                !tryOn[slot.event] || forms[slot.event].isDirty
                            "
                            :title="
                                forms[slot.event].isDirty
                                    ? $t('Save the script first.')
                                    : undefined
                            "
                            @click="tryScript(slot)"
                        >
                            <Play class="h-4 w-4" />
                            {{
                                slot.draft !== null
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

            <GuestScriptRuns :runs="logs[slot.event] ?? []" />
        </section>
    </div>
</template>
