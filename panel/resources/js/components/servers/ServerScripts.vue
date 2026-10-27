<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Play } from '@lucide/vue';
import { computed, ref } from 'vue';
import GuestScriptRuns from '@/components/admin/GuestScriptRuns.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { run } from '@/routes/admin/servers/scripts';
import type { GuestScriptRun } from '@/types/admin';

/**
 * The manual custom scripts of the server's OS template (docs/adr/0043): one
 * is run here as root (Linux) or SYSTEM (Windows), and the latest runs of
 * custom scripts on this server are listed with their output.
 */
const props = defineProps<{
    serverUuid: string;
    hostname: string;
    scripts: { uuid: string; name: string }[];
    runs: GuestScriptRun[];
    /** Not active, busy or the VM is not where the panel recorded it. */
    disabled: boolean;
}>();

const chosen = ref<string | null>(props.scripts[0]?.uuid ?? null);
const options = computed(() =>
    props.scripts.map((script) => ({ value: script.uuid, label: script.name })),
);
const chosenName = computed(
    () =>
        props.scripts.find((script) => script.uuid === chosen.value)?.name ??
        '',
);

const open = ref(false);
const processing = ref(false);

const confirm = () => {
    if (chosen.value === null) {
        return;
    }

    router.visit(run([props.serverUuid, chosen.value]), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            open.value = false;
        },
    });
};
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border p-4"
        data-test="server-scripts"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="grid gap-1">
                <h3 class="font-medium">{{ $t('Scripts') }}</h3>
                <p class="max-w-2xl text-sm text-muted-foreground">
                    {{
                        $t(
                            'Scripts of the OS template marked to run by hand. They run inside the server through the guest agent.',
                        )
                    }}
                </p>
            </div>
            <div
                v-if="scripts.length"
                class="flex flex-wrap items-center gap-2"
            >
                <NativeSelect
                    v-model="chosen"
                    :options="options"
                    :aria-label="$t('Script')"
                    class="w-56"
                />
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="disabled || chosen === null"
                    @click="open = true"
                >
                    <Play class="h-4 w-4" />
                    {{ $t('Run script') }}
                </Button>
            </div>
        </div>

        <GuestScriptRuns
            :runs="runs"
            show-script
            empty-text="No script has run on this server yet."
        />

        <Dialog v-model:open="open">
            <DialogContent>
                <DialogTitle>{{
                    $t('Run :script on :hostname?', {
                        script: chosenName,
                        hostname,
                    })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'It runs as root (Linux) or SYSTEM (Windows) for at most 5 minutes; nothing it changes is undone.',
                        )
                    }}
                </DialogDescription>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">{{ $t('Cancel') }}</Button>
                    </DialogClose>
                    <Button :disabled="processing" @click="confirm">
                        <Spinner v-if="processing" />
                        {{ $t('Run script') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
