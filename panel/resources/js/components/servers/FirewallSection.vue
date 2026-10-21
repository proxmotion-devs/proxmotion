<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import FirewallRulesEditor from '@/components/servers/FirewallRulesEditor.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { firewall as firewallRoute } from '@/routes/admin/servers';
import { ruleset as rulesetRoute } from '@/routes/admin/servers/firewall';
import type { ServerFirewall } from '@/types/admin';

/**
 * A server's firewall (docs/adr/0035): on or off, its inbound rules, a rule
 * set to apply and the ports its apps open. Saving queues an apply_firewall
 * task, like the customer's changes through the billing API.
 */
const props = defineProps<{
    server: string;
    firewall: ServerFirewall;
    disabled?: boolean;
}>();

const form = useForm({
    enabled: props.firewall.enabled,
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
        form.defaults({ enabled: firewall.enabled, rules: firewall.rules });
        form.reset();
    },
);

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
                    :tone="firewall.enabled ? 'success' : 'neutral'"
                    :label="firewall.enabled ? 'On' : 'Off'"
                />
            </h3>
        </div>
        <p class="text-sm text-muted-foreground">
            {{
                $t(
                    'When on, only the traffic these rules allow reaches the server; what it sends is never blocked. Turned on without rules, it lets SSH (Linux) or RDP (Windows) in. The console always works.',
                )
            }}
        </p>

        <form class="flex flex-col gap-3" @submit.prevent="save">
            <Label class="flex items-center gap-3 font-normal">
                <Checkbox v-model="form.enabled" :disabled="disabled" />
                {{ $t('Firewall on') }}
            </Label>
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

        <div v-if="firewall.app_rules.length > 0" class="flex flex-col gap-1">
            <p class="text-sm font-medium">
                {{ $t('Opened for apps') }}
            </p>
            <ul class="flex flex-wrap gap-2 text-xs">
                <li
                    v-for="rule in firewall.app_rules"
                    :key="`${rule.app}-${rule.protocol}-${rule.port}`"
                    class="rounded-md border px-2 py-1 font-mono"
                >
                    {{ rule.port }}/{{ rule.protocol }} · {{ rule.app }}
                </li>
            </ul>
        </div>
    </section>
</template>
