<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Ban, KeyRound, Plus } from '@lucide/vue';
import { ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useDateTime } from '@/composables/useDateTime';
import { destroy, index, store } from '@/routes/admin/api-tokens';

type ApiToken = {
    uuid: string;
    name: string;
    prefix: string;
    scopes: string[];
    allowed_ips: string[];
    creator: string | null;
    active: boolean;
    revoked: boolean;
    last_used_at: string | null;
    last_used_ip: string | null;
    expires_at: string | null;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'API tokens', href: index() }] },
});

const props = defineProps<{
    tokens: ApiToken[];
    scopes: { value: string; label: string }[];
    /** Only right after a token was created: its secret, shown once. */
    newToken: { name: string; secret: string } | null;
}>();

const { formatDateTime } = useDateTime();

const dialogOpen = ref(false);
const form = useForm({
    name: '',
    scopes: [] as string[],
    allowed_ips: '',
    expires_at: '',
});

const openForm = () => {
    form.reset();
    form.clearErrors();
    // A billing module needs every scope; narrow it down for other integrations.
    form.scopes = props.scopes.map((scope) => scope.value);
    dialogOpen.value = true;
};

const toggleScope = (scope: string, checked: boolean | 'indeterminate') => {
    form.scopes =
        checked === true
            ? [...new Set([...form.scopes, scope])]
            : form.scopes.filter((value) => value !== scope);
};

const submit = () => {
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
};

const revoking = ref<ApiToken | null>(null);
const revokeProcessing = ref(false);

const revoke = () => {
    if (!revoking.value) {
        return;
    }

    router.visit(destroy(revoking.value.uuid), {
        preserveScroll: true,
        onStart: () => (revokeProcessing.value = true),
        onFinish: () => {
            revokeProcessing.value = false;
            revoking.value = null;
        },
    });
};

const statusOf = (token: ApiToken) => {
    if (token.revoked) {
        return { tone: 'danger' as const, label: 'Revoked' };
    }

    return token.active
        ? { tone: 'success' as const, label: 'Active' }
        : { tone: 'warning' as const, label: 'Expired' };
};
</script>

<template>
    <Head title="API tokens" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="API tokens"
                description="Billing systems such as WHMCS use these tokens to create and manage servers."
            />
            <Button @click="openForm">
                <Plus class="h-4 w-4" />
                {{ $t('Create token') }}
            </Button>
        </div>

        <div
            v-if="newToken"
            class="flex flex-col gap-2 rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-4"
            data-test="new-token"
        >
            <p class="flex items-center gap-2 text-sm font-medium">
                <KeyRound class="h-4 w-4" />
                {{
                    $t(
                        'The token :name was created. Copy it now: it is shown only once.',
                        {
                            name: newToken.name,
                        },
                    )
                }}
            </p>
            <code class="font-mono text-xs break-all select-all">{{
                newToken.secret
            }}</code>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('Name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ $t('Token') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Scopes') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Allowed addresses') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Last used') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Expires') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="token in tokens"
                        :key="token.uuid"
                        class="border-t align-top"
                    >
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ token.name }}</div>
                            <div
                                v-if="token.creator"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    $t('Created by :name', {
                                        name: token.creator,
                                    })
                                }}
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ token.prefix }}…
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            <div v-for="scope in token.scopes" :key="scope">
                                {{ scope }}
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            <template v-if="token.allowed_ips.length">
                                <div v-for="ip in token.allowed_ips" :key="ip">
                                    {{ ip }}
                                </div>
                            </template>
                            <span
                                v-else
                                class="font-sans text-muted-foreground"
                                >{{ $t('Any') }}</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            {{ formatDateTime(token.last_used_at) }}
                            <div
                                v-if="token.last_used_ip"
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ token.last_used_ip }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            {{ formatDateTime(token.expires_at) }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                :tone="statusOf(token).tone"
                                :label="statusOf(token).label"
                            />
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="!token.revoked"
                                variant="ghost"
                                size="sm"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                @click="revoking = token"
                            >
                                <Ban class="h-4 w-4" />
                                {{ $t('Revoke') }}
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="tokens.length === 0">
                        <td
                            colspan="8"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            {{ $t('No API tokens yet.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <FormDialog
        v-model:open="dialogOpen"
        title="Create token"
        submit-label="Create token"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField
            label="Name"
            for="name"
            :error="form.errors.name"
            hint="For example the billing system that uses it."
        >
            <Input id="name" v-model="form.name" required autocomplete="off" />
        </FormField>

        <FormField label="Scopes" :error="form.errors.scopes">
            <div class="flex flex-col gap-2">
                <label
                    v-for="scope in scopes"
                    :key="scope.value"
                    class="flex items-start gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="form.scopes.includes(scope.value)"
                        @update:model-value="
                            (checked) => toggleScope(scope.value, checked)
                        "
                    />
                    <span>
                        {{ $t(scope.label) }}
                        <span
                            class="block font-mono text-xs text-muted-foreground"
                            >{{ scope.value }}</span
                        >
                    </span>
                </label>
            </div>
        </FormField>

        <FormField
            label="Allowed addresses"
            for="allowed_ips"
            :error="form.errors.allowed_ips"
            hint="Optional. One IP address or CIDR network per line, e.g. the address of your billing server. Empty: any address."
        >
            <textarea
                id="allowed_ips"
                v-model="form.allowed_ips"
                rows="3"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                placeholder="203.0.113.10"
            />
        </FormField>

        <FormField
            label="Expires on"
            for="expires_at"
            :error="form.errors.expires_at"
            hint="Optional. Empty: the token does not expire."
        >
            <Input id="expires_at" v-model="form.expires_at" type="date" />
        </FormField>
    </FormDialog>

    <Dialog
        :open="revoking !== null"
        @update:open="(open) => !open && (revoking = null)"
    >
        <DialogContent>
            <DialogTitle>{{
                $t('Revoke :name?', { name: revoking?.name ?? '' })
            }}</DialogTitle>
            <DialogDescription>
                {{
                    $t(
                        'Requests with this token are refused immediately. This cannot be undone.',
                    )
                }}
            </DialogDescription>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">{{ $t('Cancel') }}</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    :disabled="revokeProcessing"
                    @click="revoke"
                >
                    <Spinner v-if="revokeProcessing" />
                    {{ $t('Revoke') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
