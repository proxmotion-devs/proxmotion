<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Proxmotion mark: a hexagon (cluster) holding two stacked servers. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M20 1 36.45 10.5v19L20 39 3.55 29.5v-19L20 1Zm0 3.5L6.58 12.25v15.5L20 35.5l13.42-7.75v-15.5L20 4.5ZM14.5 13h13a1.5 1.5 0 0 1 1.5 1.5v2a1.5 1.5 0 0 1-1.5 1.5h-13a1.5 1.5 0 0 1-1.5-1.5v-2a1.5 1.5 0 0 1 1.5-1.5Zm2.5 2.5a1 1 0 1 1-2 0 1 1 0 1 1 2 0ZM12.5 21h13a1.5 1.5 0 0 1 1.5 1.5v2a1.5 1.5 0 0 1-1.5 1.5h-13a1.5 1.5 0 0 1-1.5-1.5v-2a1.5 1.5 0 0 1 1.5-1.5Zm2.5 2.5a1 1 0 1 1-2 0 1 1 0 1 1 2 0Z"
        />
    </svg>
</template>
