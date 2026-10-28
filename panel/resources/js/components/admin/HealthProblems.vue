<script setup lang="ts">
import { problemText } from '@/lib/storageHealth';
import type { HealthProblem } from '@/types/admin';

/** What is wrong with a storage or disk (docs/adr/0047). */
defineProps<{ problems: HealthProblem[] }>();
</script>

<template>
    <ul v-if="problems.length > 0" class="mt-1 flex flex-col gap-0.5 text-xs">
        <li
            v-for="(problem, index) in problems"
            :key="index"
            :class="
                problem.severity === 'critical'
                    ? 'text-red-700 dark:text-red-400'
                    : 'text-amber-700 dark:text-amber-400'
            "
        >
            {{ problemText(problem) }}
        </li>
    </ul>
</template>
