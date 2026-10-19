<script setup lang="ts">
import { Head, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Play, RotateCcw, Save } from '@lucide/vue';
import { reactive } from 'vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import CodeEditor from '@/components/apps/CodeEditor.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { destroy, run, update } from '@/routes/admin/os-templates/scripts';
import { index, scripts as scriptsPage } from '@/routes/admin/os-templates';
import type {
    GuestScriptEvent,
    GuestScriptSlot,
    OsPlatform,
    Option,
} from '@/types/admin';

const props = defineProps<{
    template: { uuid: string; name: string; platform: OsPlatform };
    scripts: GuestScriptSlot[];
    servers: Option[];
    maxBytes: number;
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
        'Runs once after the server was created or reinstalled, when the guest agent answers. There is no default script.',
    addresses_changed:
        'Configures the additional IP addresses after creation, reinstall and every resource change; also removes released ones.',
    disk_resized:
        'Grows the partition and file system right after an upgrade enlarged the disk.',
    hostname_changed:
        'Renames the system when the hostname is changed. On Windows the new name takes effect at the next restart.',
    password_reset:
        'Replaces the guest agent setting the password. The new password arrives on standard input; if the script fails, the reset fails and no new password is shown.',
};

const forms = Object.fromEntries(
    props.scripts.map((slot) => [
        slot.event,
        useForm({ script: slot.content ?? slot.default ?? '' }),
    ]),
) as Record<GuestScriptEvent, ReturnType<typeof useForm<{ script: string }>>>;

const tryOn = reactive(
    Object.fromEntries(
        props.scripts.map((slot) => [
            slot.event,
            props.servers[0]?.value ?? null,
        ]),
    ) as Record<GuestScriptEvent, string | null>,
);

const save = (event: GuestScriptEvent) => {
    forms[event].submit(update([props.template.uuid, event]), {
        preserveScroll: true,
    });
};

const reset = (slot: GuestScriptSlot) => {
    router.visit(destroy([props.template.uuid, slot.event]), {
        preserveScroll: true,
        onSuccess: () => {
            forms[slot.event].defaults({ script: slot.default ?? '' });
            forms[slot.event].reset();
        },
    });
};

const tryScript = (event: GuestScriptEvent) => {
    router.visit(run([props.template.uuid, event]), {
        data: { server: tryOn[event] },
        preserveScroll: true,
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

        <section
            v-for="slot in scripts"
            :key="slot.event"
            class="flex flex-col gap-3 rounded-xl border p-4"
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
                    </h2>
                    <p class="max-w-3xl text-sm text-muted-foreground">
                        {{ $t(eventDescriptions[slot.event]) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ $t('Inputs') }}:
                        <code class="font-mono">{{
                            slot.inputs.join(', ')
                        }}</code>
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

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    :disabled="
                        forms[slot.event].processing ||
                        !forms[slot.event].isDirty
                    "
                    @click="save(slot.event)"
                >
                    <Save class="h-4 w-4" />
                    {{ $t('Save') }}
                </Button>
                <Button
                    v-if="slot.content !== null"
                    variant="outline"
                    @click="reset(slot)"
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
                        (slot.content !== null || slot.default !== null)
                    "
                    class="ml-auto flex flex-wrap items-center gap-2"
                >
                    <template v-if="servers.length">
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
                            @click="tryScript(slot.event)"
                        >
                            <Play class="h-4 w-4" />
                            {{ $t('Run on server') }}
                        </Button>
                    </template>
                    <span v-else class="text-xs text-muted-foreground">{{
                        $t('No active server uses this template.')
                    }}</span>
                </div>
            </div>
        </section>
    </div>
</template>
