<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import FirewallRulesEditor from '@/components/servers/FirewallRulesEditor.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    destroy,
    index,
    store,
    update,
} from '@/routes/admin/firewall-rulesets';
import type { FirewallRuleValues, FirewallRuleset } from '@/types/admin';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Firewall rule sets', href: index() }],
    },
});

defineProps<{ rulesets: FirewallRuleset[] }>();

const dialogOpen = ref(false);
const editing = ref<FirewallRuleset | null>(null);

const starter: FirewallRuleValues[] = [
    {
        action: 'accept',
        protocol: 'tcp',
        port: '22',
        source: null,
        comment: 'SSH',
    },
];

const form = useForm({
    name: '',
    description: '',
    is_active: true,
    rules: starter,
});

/** Whether the rules let SSH (22) or RDP (3389) in, like FirewallRule::opens(). */
const opensRemoteAccess = computed(() =>
    form.rules.some((rule) => {
        if (
            rule.action !== 'accept' ||
            !['tcp', 'any'].includes(rule.protocol)
        ) {
            return false;
        }

        if (!rule.port) {
            return true;
        }

        const [from, to = from] = rule.port.split('-').map(Number);

        return [22, 3389].some((port) => port >= from && port <= to);
    }),
);

const openForm = (ruleset: FirewallRuleset | null) => {
    editing.value = ruleset;
    form.clearErrors();
    form.defaults({
        name: ruleset?.name ?? '',
        description: ruleset?.description ?? '',
        is_active: ruleset?.is_active ?? true,
        rules: ruleset ? [...ruleset.rules] : starter,
    });
    form.reset();
    dialogOpen.value = true;
};

const submit = () => {
    const action = editing.value ? update(editing.value.uuid) : store();

    form.submit(action, {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
};

const summary = (rules: FirewallRuleValues[]) =>
    rules
        .map((rule) =>
            [
                rule.action === 'accept' ? '✓' : '✗',
                rule.port ? `${rule.port}/${rule.protocol}` : rule.protocol,
            ].join(' '),
        )
        .join(', ');
</script>

<template>
    <Head title="Firewall rule sets" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Firewall rule sets"
                description="Inbound rules customers apply to their servers in one go. A package can start its new servers with one."
            />
            <Button @click="openForm(null)">
                <Plus class="h-4 w-4" />
                {{ $t('Add rule set') }}
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('Name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ $t('Rules') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Packages') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="ruleset in rulesets"
                        :key="ruleset.uuid"
                        class="border-t"
                    >
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ ruleset.name }}</div>
                            <div
                                v-if="ruleset.description"
                                class="text-xs text-muted-foreground"
                            >
                                {{ ruleset.description }}
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ summary(ruleset.rules) || '—' }}
                            <div
                                v-if="!ruleset.opens_remote_access"
                                class="mt-1 flex items-center gap-1 font-sans text-amber-700 dark:text-amber-400"
                            >
                                <TriangleAlert class="h-3 w-3" />
                                {{ $t('Lets neither SSH nor RDP in') }}
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ ruleset.packages_count }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                :tone="
                                    ruleset.is_active ? 'success' : 'neutral'
                                "
                                :label="
                                    ruleset.is_active ? 'Active' : 'Inactive'
                                "
                            />
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="
                                        $t('Edit :name', { name: ruleset.name })
                                    "
                                    @click="openForm(ruleset)"
                                >
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <ConfirmDelete
                                    :action="destroy(ruleset.uuid)"
                                    :name="ruleset.name"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="rulesets.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{
                                $t(
                                    'No rule sets yet. Add one, e.g. "Web server" with SSH, HTTP and HTTPS.',
                                )
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <FormDialog
        v-model:open="dialogOpen"
        :title="editing ? 'Edit rule set' : 'Add rule set'"
        :processing="form.processing"
        wide
        @submit="submit"
    >
        <FormField label="Name" for="name" :error="form.errors.name">
            <Input
                id="name"
                v-model="form.name"
                required
                placeholder="Web server"
            />
        </FormField>
        <FormField
            label="Description"
            for="description"
            :error="form.errors.description"
        >
            <Input id="description" v-model="form.description" />
        </FormField>
        <Label class="flex items-center gap-3 font-normal">
            <Checkbox v-model="form.is_active" />
            {{ $t('Active (offered to customers)') }}
        </Label>
        <FirewallRulesEditor v-model="form.rules" :errors="form.errors" />
        <p
            v-if="!opensRemoteAccess"
            class="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400"
        >
            <TriangleAlert class="h-4 w-4 shrink-0" />
            {{
                $t(
                    'These rules let neither SSH (22) nor RDP (3389) in: customers can then reach their servers only through the console.',
                )
            }}
        </p>
    </FormDialog>
</template>
