<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Star } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Change the primary address of one IP version (docs/adr/0011): one of the
 * server's additional addresses (swapped), the next free address of its
 * pools or a specific free one (the old primary is released).
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    addresses: { address: string; version: 4 | 6; primary: boolean }[];
    disabled?: boolean;
}>();

const NEXT = '__next__';
const SPECIFIC = '__specific__';

const open = ref(false);
const choice = ref<string | null>(null);
const specific = ref('');
const form = useForm({
    address: null as string | null,
    ip_version: 4 as 4 | 6,
});

const versions = computed(() =>
    ([4, 6] as const).filter((version) =>
        props.addresses.some((address) => address.version === version),
    ),
);
const versionOptions = computed(() =>
    versions.value.map((version) => ({
        value: String(version),
        label: `IPv${version}`,
    })),
);
const versionValue = computed({
    get: () => String(form.ip_version),
    set: (value: string | null) => {
        form.ip_version = value === '6' ? 6 : 4;
        choice.value = NEXT;
    },
});

const current = computed(
    () =>
        props.addresses.find(
            (address) => address.version === form.ip_version && address.primary,
        )?.address ?? null,
);
const others = computed(() =>
    props.addresses.filter(
        (address) => address.version === form.ip_version && !address.primary,
    ),
);
const choices = computed(() => [
    ...others.value.map((address) => ({
        value: address.address,
        label: address.address,
    })),
    { value: NEXT, label: trans('Next free address of the pool') },
    { value: SPECIFIC, label: trans('A specific free address…') },
]);
const releases = computed(
    () => choice.value === NEXT || choice.value === SPECIFIC,
);

const openDialog = () => {
    form.clearErrors();
    form.ip_version = versions.value[0] ?? 4;
    choice.value = others.value[0]?.address ?? NEXT;
    specific.value = '';
    open.value = true;
};

const submit = () => {
    form.address =
        choice.value === NEXT
            ? null
            : choice.value === SPECIFIC
              ? specific.value.trim()
              : choice.value;

    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
};
</script>

<template>
    <Button
        variant="outline"
        size="sm"
        :disabled="disabled || versions.length === 0"
        @click="openDialog"
    >
        <Star class="h-4 w-4" />
        {{ $t('Change primary address') }}
    </Button>

    <FormDialog
        v-model:open="open"
        title="Change primary address"
        description="The primary address is the one the server sends from. It is changed inside the server through the guest agent, without a restart. The number of addresses stays the same."
        submit-label="Change"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField
            v-if="versions.length > 1"
            label="IP version"
            for="ip_version"
            :error="form.errors.ip_version"
        >
            <NativeSelect
                id="ip_version"
                v-model="versionValue"
                :options="versionOptions"
            />
        </FormField>

        <p class="text-sm">
            {{ $t('Current primary') }}:
            <span class="font-mono">{{ current ?? '—' }}</span>
        </p>

        <FormField
            label="New primary"
            for="new_primary"
            :error="form.errors.address"
        >
            <NativeSelect
                id="new_primary"
                v-model="choice"
                :options="choices"
            />
        </FormField>

        <FormField
            v-if="choice === SPECIFIC"
            label="Address"
            for="specific_address"
            hint="A free address of a pool of this location on the same bridge and VLAN."
        >
            <Input
                id="specific_address"
                v-model="specific"
                class="font-mono"
                autocomplete="off"
                required
            />
        </FormField>

        <p
            v-if="releases"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
        >
            {{
                $t(
                    ':address is released back to its pool and its reverse DNS record is removed.',
                    { address: current ?? '' },
                )
            }}
        </p>
        <p v-else class="text-sm text-muted-foreground">
            {{
                $t(':address stays on the server as an additional address.', {
                    address: current ?? '',
                })
            }}
        </p>
    </FormDialog>
</template>
