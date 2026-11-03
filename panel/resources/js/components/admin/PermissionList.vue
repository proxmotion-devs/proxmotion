<script setup lang="ts">
import { AlertTriangle, CircleCheck, CircleX } from '@lucide/vue';
import type { PermissionCheck } from '@/types/admin';

/**
 * What a token permission check found: per path, the privileges missing and
 * those it should not have.
 */
defineProps<{ entries: PermissionCheck }>();
</script>

<template>
    <ul class="flex flex-col gap-2 text-sm">
        <li
            v-for="entry in entries"
            :key="entry.path"
            class="flex flex-wrap items-center gap-2 rounded-lg border p-3"
        >
            <CircleX
                v-if="entry.missing.length"
                class="h-4 w-4 text-status-danger-text"
            />
            <AlertTriangle
                v-else-if="entry.excess.length"
                class="h-4 w-4 text-status-warning-text"
            />
            <CircleCheck
                v-else
                class="h-4 w-4 text-status-success-foreground"
            />
            <span class="font-mono text-xs">{{ entry.path }}</span>
            <span class="text-muted-foreground">{{ $t(entry.purpose) }}</span>
            <span
                v-if="entry.missing.length"
                class="w-full font-mono text-xs text-status-danger-text"
            >
                {{
                    $t('Missing: :privileges', {
                        privileges: entry.missing.join(', '),
                    })
                }}
            </span>
            <span
                v-if="entry.excess.length"
                class="w-full font-mono text-xs text-status-warning-text"
            >
                {{
                    $t('More than needed: :privileges', {
                        privileges: entry.excess.join(', '),
                    })
                }}
            </span>
        </li>
    </ul>
</template>
