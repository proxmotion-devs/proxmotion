<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import type { AppText, RevealedAppOutputs } from '@/types/admin';

/**
 * What an installed app reported (docs/adr/0015): text values to copy and
 * files to download, then the outputs of its latest actions (e.g. a new VPN
 * client). Shown once after Show details; nothing is kept in the browser.
 */
defineProps<{ revealed: RevealedAppOutputs }>();

const page = usePage<{ locale: string }>();
const { formatDateTime } = useDateTime();

const text = (value: AppText | null, fallback: string) =>
    value
        ? ((page.props.locale === 'tr' ? value.tr : null) ?? value.en)
        : fallback;

const fileHref = (contentBase64: string) =>
    `data:application/octet-stream;base64,${contentBase64}`;
</script>

<template>
    <div class="flex flex-col gap-4" data-test="app-outputs">
        <template
            v-for="group in [
                { key: 'install', title: null, outputs: revealed.outputs },
                ...revealed.runs.map((run) => ({
                    key: run.uuid,
                    title: `${text(run.action, '')} · ${formatDateTime(run.created_at)}`,
                    outputs: run.outputs,
                })),
            ]"
            :key="group.key"
        >
            <div v-if="group.outputs.length" class="flex flex-col gap-2">
                <div
                    v-if="group.title"
                    class="text-xs font-medium text-muted-foreground"
                >
                    {{ group.title }}
                </div>
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div v-for="output in group.outputs" :key="output.key">
                        <dt class="text-xs text-muted-foreground">
                            {{ text(output.label, output.key) }}
                        </dt>
                        <dd v-if="output.content_base64 !== null" class="mt-1">
                            <Button variant="outline" size="sm" as-child>
                                <a
                                    :href="fileHref(output.content_base64)"
                                    :download="output.filename ?? output.key"
                                >
                                    <Download class="h-4 w-4" />
                                    {{ output.filename ?? output.key }}
                                </a>
                            </Button>
                        </dd>
                        <dd
                            v-else
                            class="mt-1 font-mono text-xs break-all whitespace-pre-wrap select-all"
                        >
                            {{ output.value }}
                        </dd>
                    </div>
                </dl>
            </div>
        </template>
        <p
            v-if="
                revealed.outputs.length === 0 &&
                revealed.runs.every((run) => run.outputs.length === 0)
            "
            class="text-sm text-muted-foreground"
        >
            {{ $t('The app has not reported anything to show.') }}
        </p>
    </div>
</template>
