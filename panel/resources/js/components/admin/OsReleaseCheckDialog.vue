<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { OsReleaseResult } from '@/lib/osTemplates';
import { osRelease } from '@/routes/admin/os-templates';
import { create as createServer } from '@/routes/admin/servers';
import type { OsTemplate } from '@/types/admin';

/**
 * Compares an OS template with /etc/os-release of a running server built
 * from it (docs/adr/0024 §8, 0026): what the server reports next to the
 * template's system and version. Nothing is saved.
 */
const props = defineProps<{
    template: OsTemplate;
}>();

const open = defineModel<boolean>('open', { required: true });

const server = ref<string | null>(null);
const servers = ref<{ value: string; label: string }[]>([]);
const result = ref<OsReleaseResult | null>(null);
const error = ref<string | null>(null);
// No running server uses the template yet: the dialog offers to create one.
const noServer = ref(false);
const loading = ref(false);
// Answers for an earlier request are dropped.
let generation = 0;

const load = async (wanted: string | null) => {
    const current = ++generation;
    loading.value = true;
    error.value = null;
    noServer.value = false;
    result.value = null;

    try {
        const response = await fetch(
            osRelease.url(props.template.uuid, {
                query: wanted === null ? {} : { server: wanted },
            }),
            { headers: { Accept: 'application/json' } },
        );
        const body = await response.json().catch(() => ({}));

        if (current !== generation) {
            return;
        }

        servers.value = body.servers ?? servers.value;
        server.value = body.server ?? wanted;

        if (response.ok) {
            result.value = body as OsReleaseResult;
        } else {
            error.value =
                body.message ?? trans('The server could not be checked.');
            noServer.value = body.no_server === true;
        }
    } catch {
        if (current === generation) {
            error.value = trans('The server could not be checked.');
        }
    } finally {
        if (current === generation) {
            loading.value = false;
        }
    }
};

watch(
    open,
    (value) => {
        if (value) {
            servers.value = [];
            void load(null);
        }
    },
    { immediate: true },
);

const pick = (value: string | null) => {
    if (value !== null && value !== server.value) {
        void load(value);
    }
};

const reported = computed(() => {
    const release = result.value?.os_release;

    if (!release) {
        return '';
    }

    return [
        `ID=${release.id}`,
        release.id_like.length ? `ID_LIKE="${release.id_like.join(' ')}"` : '',
        release.version_id ? `VERSION_ID=${release.version_id}` : '',
    ]
        .filter(Boolean)
        .join(' · ');
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl" data-test="os-release-check">
            <DialogHeader>
                <DialogTitle>
                    {{
                        $t('Check :name against a server', {
                            name: template.name,
                        })
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Reads /etc/os-release of a running server built from this template through its guest agent. Nothing is saved until you save the template.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="servers.length > 1" class="grid gap-1">
                <label for="os-release-server" class="text-sm font-medium">{{
                    $t('Server')
                }}</label>
                <NativeSelect
                    id="os-release-server"
                    :model-value="server"
                    :options="servers"
                    :disabled="loading"
                    @update:model-value="pick"
                />
            </div>

            <div
                v-if="loading"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <Spinner />
                {{ $t('Reading /etc/os-release…') }}
            </div>
            <p
                v-else-if="error"
                class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
                data-test="os-release-error"
            >
                {{ error }}
                <Link
                    v-if="noServer"
                    :href="
                        createServer({ query: { os_template: template.uuid } })
                    "
                    class="mt-2 block font-medium underline"
                    data-test="os-release-create-server"
                >
                    {{ $t('Create a server from this template') }}
                </Link>
            </p>
            <template v-else-if="result">
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-muted-foreground">{{ $t('Template') }}</dt>
                    <dd class="font-medium">{{ result.template_system }}</dd>
                    <dt class="text-muted-foreground">{{ $t('Server') }}</dt>
                    <dd class="font-medium">{{ result.server_system }}</dd>
                </dl>
                <span class="font-mono text-xs text-muted-foreground">{{
                    reported
                }}</span>
                <p
                    v-if="result.version_matches === true"
                    class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm"
                    data-test="os-release-match"
                >
                    {{ $t('The version matches the server.') }}
                </p>
                <p
                    v-else-if="result.version_matches === false"
                    class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
                    data-test="os-release-mismatch"
                >
                    {{
                        $t(
                            'The server reports another version. Check that the template is of the version you picked.',
                        )
                    }}
                </p>
            </template>

            <DialogFooter>
                <Button variant="outline" @click="open = false">{{
                    $t('Close')
                }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
