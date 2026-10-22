<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import FirewallRulesEditor from '@/components/servers/FirewallRulesEditor.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { firewall as firewallRoute } from '@/routes/admin/servers';
import { ruleset as rulesetRoute } from '@/routes/admin/servers/firewall';
import type { ServerFirewall } from '@/types/admin';

/**
 * A server's firewall (docs/adr/0035): what happens to traffic no rule
 * matches, its inbound rules (always applied), a rule set to apply and the
 * ports its apps open. Saving queues an apply_firewall
 * task, like the customer's changes through the billing API.
 */
const props = defineProps<{
    server: string;
    firewall: ServerFirewall;
    disabled?: boolean;
}>();

const form = useForm({
    default_policy: props.firewall.default_policy,
    rules: props.firewall.rules,
});

const rulesetForm = useForm({
    ruleset: (props.firewall.rulesets[0]?.value ?? null) as string | null,
    mode: 'replace' as 'replace' | 'append',
});

// After a save the page reloads the firewall as stored (a default rule may have been added).
watch(
    () => props.firewall,
    (firewall) => {
        form.defaults({
            default_policy: firewall.default_policy,
            rules: firewall.rules,
        });
        form.reset();
    },
);

const policies = computed(() => [
    { value: 'accept' as const, label: trans('Allow it') },
    {
        value: 'drop' as const,
        label: trans('Block it: only what the rules allow comes in'),
    },
]);

const modes = computed(() => [
    { value: 'replace' as const, label: trans('Replace the rules') },
    { value: 'append' as const, label: trans('Add to the rules') },
]);

const save = () =>
    form.submit(firewallRoute(props.server), { preserveScroll: true });

const applyRuleset = () =>
    rulesetForm.submit(rulesetRoute(props.server), { preserveScroll: true });
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border p-4"
        data-test="server-firewall"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="flex items-center gap-2 font-medium">
                <ShieldCheck class="h-4 w-4" />
                {{ $t('Firewall') }}
                <StatusBadge
                    :tone="
                        firewall.default_policy === 'drop'
                            ? 'success'
                            : 'neutral'
                    "
                    :label="
                        firewall.default_policy === 'drop'
                            ? 'Blocks by default'
                            : 'Allows by default'
                    "
                />
            </h3>
        </div>
        <p class="text-sm text-muted-foreground">
            {{
                $t(
                    'The rules always apply, top to bottom; traffic no rule matches is allowed or blocked as chosen here. What the server sends is never blocked. Switched to block without a rule that allows anything, it lets SSH (Linux) or RDP (Windows) in. The console always works.',
                )
            }}
        </p>

        <form class="flex flex-col gap-3" @submit.prevent="save">
            <div class="flex max-w-md flex-col gap-1">
                <Label for="firewall-policy">{{
                    $t('Traffic no rule matches')
                }}</Label>
                <NativeSelect
                    id="firewall-policy"
                    v-model="form.default_policy"
                    :options="policies"
                    :disabled="disabled"
                />
            </div>
            <FirewallRulesEditor v-model="form.rules" :errors="form.errors" />
            <div class="flex gap-2">
                <Button
                    type="submit"
                    size="sm"
                    :disabled="disabled || form.processing || !form.isDirty"
                >
                    {{ $t('Save firewall') }}
                </Button>
                <Button
                    v-if="form.isDirty"
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="form.reset()"
                >
                    {{ $t('Discard changes') }}
                </Button>
            </div>
        </form>

        <form
            v-if="firewall.rulesets.length > 0"
            class="flex flex-wrap items-end gap-2"
            @submit.prevent="applyRuleset"
        >
            <div class="flex min-w-48 flex-col gap-1">
                <Label for="firewall-ruleset">{{ $t('Rule set') }}</Label>
                <NativeSelect
                    id="firewall-ruleset"
                    v-model="rulesetForm.ruleset"
                    :options="firewall.rulesets"
                />
            </div>
            <div class="flex min-w-48 flex-col gap-1">
                <Label for="firewall-ruleset-mode">{{ $t('How') }}</Label>
                <NativeSelect
                    id="firewall-ruleset-mode"
                    v-model="rulesetForm.mode"
                    :options="modes"
                />
            </div>
            <Button
                type="submit"
                size="sm"
                variant="outline"
                :disabled="disabled || rulesetForm.processing"
            >
                {{ $t('Apply rule set') }}
            </Button>
            <p
                v-if="rulesetForm.errors.ruleset"
                class="w-full text-sm text-destructive"
            >
                {{ rulesetForm.errors.ruleset }}
            </p>
        </form>

        <div v-if="firewall.app_rules.length > 0" class="flex flex-col gap-2">
            <p class="text-sm font-medium">
                {{ $t('Opened for apps') }}
            </p>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm" data-test="firewall-app-rules">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">
                                {{ $t('Port') }}
                            </th>
                            <th class="px-4 py-2 font-medium">
                                {{ $t('Protocol') }}
                            </th>
                            <th class="px-4 py-2 font-medium">
                                {{ $t('App') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="rule in firewall.app_rules"
                            :key="`${rule.app}-${rule.protocol}-${rule.port}`"
                            class="border-t"
                        >
                            <td class="px-4 py-2 font-mono text-xs">
                                {{ rule.port }}
                            </td>
                            <td class="px-4 py-2 font-mono text-xs uppercase">
                                {{ rule.protocol }}
                            </td>
                            <td class="px-4 py-2">{{ rule.app }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</template>
