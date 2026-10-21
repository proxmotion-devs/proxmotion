<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { FirewallRuleValues } from '@/types/admin';

/**
 * The inbound rules of a server's firewall or of a rule set, in order
 * (docs/adr/0035): add, change, move and delete them. Errors come as
 * "rules.<index>.<field>".
 */
const props = withDefaults(
    defineProps<{
        errors?: Partial<Record<string, string>>;
        /** Error keys start with this, e.g. "rules". */
        errorKey?: string;
        max?: number;
    }>(),
    { errors: () => ({}), errorKey: 'rules', max: 50 },
);

const rules = defineModel<FirewallRuleValues[]>({ required: true });

const actions = computed(() => [
    { value: 'accept' as const, label: trans('Allow') },
    { value: 'drop' as const, label: trans('Block') },
]);

const protocols = computed(() => [
    { value: 'tcp' as const, label: 'TCP' },
    { value: 'udp' as const, label: 'UDP' },
    { value: 'icmp' as const, label: 'ICMP' },
    { value: 'any' as const, label: trans('Any') },
]);

const withPort = (rule: FirewallRuleValues) =>
    rule.protocol === 'tcp' || rule.protocol === 'udp';

const update = (index: number, changes: Partial<FirewallRuleValues>) => {
    rules.value = rules.value.map((rule, i) => {
        if (i !== index) {
            return rule;
        }

        const changed = { ...rule, ...changes };

        // Only TCP and UDP have ports.
        return withPort(changed) ? changed : { ...changed, port: null };
    });
};

const text = (value: string | number): string | null =>
    String(value).trim() === '' ? null : String(value);

const add = () => {
    rules.value = [
        ...rules.value,
        {
            action: 'accept',
            protocol: 'tcp',
            port: null,
            source: null,
            comment: null,
        },
    ];
};

const remove = (index: number) => {
    rules.value = rules.value.filter((_, i) => i !== index);
};

const move = (index: number, by: -1 | 1) => {
    const target = index + by;

    if (target < 0 || target >= rules.value.length) {
        return;
    }

    const list = [...rules.value];
    [list[index], list[target]] = [list[target], list[index]];
    rules.value = list;
};

const error = (index: number, field: string) =>
    props.errors[`${props.errorKey}.${index}.${field}`];

const rowErrors = (index: number) =>
    ['action', 'protocol', 'port', 'source', 'comment']
        .map((field) => error(index, field))
        .filter((message): message is string => !!message);
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[44rem] text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="w-10 px-3 py-2 font-medium">#</th>
                        <th class="w-28 px-2 py-2 font-medium">
                            {{ $t('Action') }}
                        </th>
                        <th class="w-28 px-2 py-2 font-medium">
                            {{ $t('Protocol') }}
                        </th>
                        <th class="w-32 px-2 py-2 font-medium">
                            {{ $t('Port') }}
                        </th>
                        <th class="px-2 py-2 font-medium">
                            {{ $t('Source') }}
                        </th>
                        <th class="px-2 py-2 font-medium">
                            {{ $t('Comment') }}
                        </th>
                        <th class="w-32 px-2 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <template v-for="(rule, index) in rules" :key="index">
                        <tr class="border-t" data-test="firewall-rule">
                            <td
                                class="px-3 py-2 font-mono text-xs text-muted-foreground"
                            >
                                {{ index + 1 }}
                            </td>
                            <td class="px-2 py-2">
                                <NativeSelect
                                    :model-value="rule.action"
                                    :options="actions"
                                    :aria-label="$t('Action')"
                                    @update:model-value="
                                        (value) =>
                                            update(index, {
                                                action: value ?? 'accept',
                                            })
                                    "
                                />
                            </td>
                            <td class="px-2 py-2">
                                <NativeSelect
                                    :model-value="rule.protocol"
                                    :options="protocols"
                                    :aria-label="$t('Protocol')"
                                    @update:model-value="
                                        (value) =>
                                            update(index, {
                                                protocol: value ?? 'tcp',
                                            })
                                    "
                                />
                            </td>
                            <td class="px-2 py-2">
                                <Input
                                    :model-value="rule.port ?? ''"
                                    :placeholder="
                                        withPort(rule) ? '22, 1000-2000' : '—'
                                    "
                                    :disabled="!withPort(rule)"
                                    :aria-label="$t('Port')"
                                    class="font-mono"
                                    @update:model-value="
                                        (value) =>
                                            update(index, { port: text(value) })
                                    "
                                />
                            </td>
                            <td class="px-2 py-2">
                                <Input
                                    :model-value="rule.source ?? ''"
                                    :placeholder="$t('From anywhere')"
                                    :aria-label="$t('Source')"
                                    class="font-mono"
                                    @update:model-value="
                                        (value) =>
                                            update(index, {
                                                source: text(value),
                                            })
                                    "
                                />
                            </td>
                            <td class="px-2 py-2">
                                <Input
                                    :model-value="rule.comment ?? ''"
                                    :placeholder="$t('Comment')"
                                    :aria-label="$t('Comment')"
                                    maxlength="60"
                                    @update:model-value="
                                        (value) =>
                                            update(index, {
                                                comment: text(value),
                                            })
                                    "
                                />
                            </td>
                            <td class="px-2 py-2">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        :disabled="index === 0"
                                        :aria-label="$t('Move up')"
                                        @click="move(index, -1)"
                                    >
                                        <ArrowUp class="h-4 w-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        :disabled="index === rules.length - 1"
                                        :aria-label="$t('Move down')"
                                        @click="move(index, 1)"
                                    >
                                        <ArrowDown class="h-4 w-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="$t('Delete rule')"
                                        @click="remove(index)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="rowErrors(index).length > 0">
                            <td />
                            <td colspan="6" class="px-2 pb-2">
                                <p
                                    v-for="message in rowErrors(index)"
                                    :key="message"
                                    class="text-sm text-destructive"
                                >
                                    {{ message }}
                                </p>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="rules.length === 0">
                        <td
                            colspan="7"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            {{ $t('No rules.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="errors[errorKey]" class="text-sm text-destructive">
            {{ errors[errorKey] }}
        </p>

        <div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="rules.length >= max"
                @click="add"
            >
                <Plus class="h-4 w-4" />
                {{ $t('Add rule') }}
            </Button>
        </div>
    </div>
</template>
