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
            <CircleX v-if="entry.missing.length" class="h-4 w-4 text-red-600" />
            <AlertTriangle
                v-else-if="entry.excess.length"
                class="h-4 w-4 text-amber-600"
            />
            <CircleCheck v-else class="h-4 w-4 text-emerald-600" />
            <span class="font-mono text-xs">{{ entry.path }}</span>
            <span class="text-muted-foreground">{{ $t(entry.purpose) }}</span>
            <span
                v-if="entry.missing.length"
                class="w-full font-mono text-xs text-red-700 dark:text-red-400"
            >
                {{
                    $t('Missing: :privileges', {
                        privileges: entry.missing.join(', '),
                    })
                }}
            </span>
            <span
                v-if="entry.excess.length"
                class="w-full font-mono text-xs text-amber-700 dark:text-amber-400"
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
