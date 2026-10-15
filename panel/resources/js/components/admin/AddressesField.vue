<script setup lang="ts">
import FormField from '@/components/admin/FormField.vue';

defineProps<{
    error?: string;
    ipv6?: boolean;
    label?: string;
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <FormField
        :label="label ?? 'Addresses'"
        for="addresses"
        :error="error"
        :hint="
            ipv6
                ? 'One or more IPv6 addresses, separated by commas, spaces or new lines.'
                : 'Single IPs, lists, ranges (203.0.113.10-203.0.113.20 or 203.0.113.10-20) or blocks (203.0.113.144/29). Separate with commas, spaces or new lines.'
        "
    >
        <textarea
            id="addresses"
            v-model="model"
            rows="4"
            class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
            :placeholder="
                ipv6
                    ? '2001:db8:100::10\n2001:db8:100::11'
                    : '203.0.113.10\n203.0.113.20-25\n203.0.113.144/29'
            "
        />
    </FormField>
</template>
