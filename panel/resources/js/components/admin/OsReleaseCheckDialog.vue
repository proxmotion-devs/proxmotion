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
import type { OsReleaseResult, OsTemplateValues } from '@/lib/osTemplates';
import { osRelease } from '@/routes/admin/os-templates';
import { create as createServer } from '@/routes/admin/servers';
import type { OsTemplate } from '@/types/admin';

/**
 * Compares an OS template with /etc/os-release of a running server built
 * from it (docs/adr/0024 §8). Nothing is saved here: "Use the server's
 * values" opens the edit form with them.
 */
const props = defineProps<{
    template: OsTemplate;
    /** Display names of distributions and families. */
    nameOf: (field: string, value: string | null) => string;
}>();

const emit = defineEmits<{ apply: [values: OsTemplateValues] }>();

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

const FIELDS: Record<string, string> = {
    distribution: 'Distribution',
    version: 'Version',
    family: 'Family',
    compatible_with: 'Compatible with',
    compatible_version: 'Its version',
};

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

const apply = () => {
    if (result.value) {
        emit('apply', result.value.suggestion);
        open.value = false;
    }
};
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
                <div class="grid gap-1 text-sm">
                    <span class="font-medium">{{
                        result.os_release.pretty_name ?? result.os_release.id
                    }}</span>
                    <span class="font-mono text-xs text-muted-foreground">{{
                        reported
                    }}</span>
                </div>
                <p
                    v-if="result.differences.length === 0"
                    class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm"
                    data-test="os-release-match"
                >
                    {{ $t('The template matches the server.') }}
                </p>
                <div v-else class="overflow-x-auto rounded-lg border">
                    <table
                        class="w-full text-sm"
                        data-test="os-release-differences"
                    >
                        <thead
                            class="bg-muted/50 text-left text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2 font-medium" />
                                <th class="px-3 py-2 font-medium">
                                    {{ $t('Template') }}
                                </th>
                                <th class="px-3 py-2 font-medium">
                                    {{ $t('Server') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="difference in result.differences"
                                :key="difference.field"
                                class="border-t"
                            >
                                <td class="px-3 py-2 font-medium">
                                    {{
                                        $t(
                                            FIELDS[difference.field] ??
                                                difference.field,
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-2">
                                    {{
                                        nameOf(
                                            difference.field,
                                            difference.template,
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-2">
                                    {{
                                        nameOf(
                                            difference.field,
                                            difference.server,
                                        )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="
                        result.suggestion.compatible_with &&
                        !result.suggestion.compatible_version
                    "
                    class="text-xs text-muted-foreground"
                >
                    {{
                        $t(
                            'The version of the system it is compatible with is not in /etc/os-release: enter it in the form.',
                        )
                    }}
                </p>
            </template>

            <DialogFooter>
                <Button variant="outline" @click="open = false">{{
                    $t('Close')
                }}</Button>
                <Button
                    v-if="result && result.differences.length"
                    data-test="os-release-apply"
                    @click="apply"
                >
                    {{ $t("Use the server's values") }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
