<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import PermissionList from '@/components/admin/PermissionList.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { incoming } from '@/routes/admin/clusters';
import { permissions as incomingPermissions } from '@/routes/admin/clusters/incoming';
import type { Cluster, PermissionCheck } from '@/types/admin';

/**
 * The token servers from other clusters are moved in with (docs/adr/0037 §5):
 * a separate, narrow token with Sys.Incoming, never the panel's own. Its
 * secret is handed to the source cluster's Proxmox during each move.
 */
const props = defineProps<{
    cluster: Cluster;
    permissionCheck: PermissionCheck | null;
}>();

const open = ref(false);
const form = useForm({
    incoming_token_id: '',
    incoming_token_secret: '',
    tls_fingerprint: '',
});

const openDialog = () => {
    form.clearErrors();
    form.defaults({
        incoming_token_id: props.cluster.incoming_token_id ?? '',
        incoming_token_secret: '',
        tls_fingerprint: props.cluster.tls_fingerprint ?? '',
    });
    form.reset();
    open.value = true;
};

const submit = () =>
    form.submit(incoming(props.cluster.uuid), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });

const checkPermissions = () =>
    router.visit(incomingPermissions(props.cluster.uuid), {
        preserveScroll: true,
    });
</script>

<template>
    <section class="flex flex-col gap-4" data-test="incoming-moves">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                variant="small"
                title="Servers from other clusters"
                description="To receive servers moved from another cluster, this cluster needs its own incoming move token. It is used for nothing else."
            />
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="cluster.receives_moves"
                    variant="outline"
                    @click="checkPermissions"
                >
                    <ShieldCheck class="h-4 w-4" />
                    {{ $t('Check permissions') }}
                </Button>
                <Button variant="outline" @click="openDialog">
                    <KeyRound class="h-4 w-4" />
                    {{
                        $t(
                            cluster.receives_moves
                                ? 'Change token'
                                : 'Add incoming move token',
                        )
                    }}
                </Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <StatusBadge
                v-if="cluster.receives_moves"
                tone="success"
                label="Receives servers"
            />
            <StatusBadge v-else tone="neutral" label="Receives no servers" />
            <span
                v-if="cluster.incoming_token_id"
                class="font-mono text-xs text-muted-foreground"
                >{{ cluster.incoming_token_id }}</span
            >
        </div>

        <PermissionList v-if="permissionCheck" :entries="permissionCheck" />

        <FormDialog
            v-model:open="open"
            title="Incoming move token"
            description="Create a separate user and token in Proxmox with Sys.Incoming and Sys.Audit on /, VM.Allocate on /vms, Datastore.AllocateSpace on /storage, SDN.Use on the bridges and Pool.Allocate on the resource pool. Leave the token ID empty to remove it."
            :processing="form.processing"
            @submit="submit"
        >
            <FormField
                label="Token ID"
                for="incoming_token_id"
                :error="form.errors.incoming_token_id"
            >
                <Input
                    id="incoming_token_id"
                    v-model="form.incoming_token_id"
                    class="font-mono"
                    placeholder="proxmotion-incoming@pve!moves"
                    autocomplete="off"
                />
            </FormField>
            <FormField
                label="Token secret"
                for="incoming_token_secret"
                :error="form.errors.incoming_token_secret"
                :hint="
                    cluster.receives_moves
                        ? 'Leave empty to keep the saved secret.'
                        : undefined
                "
            >
                <Input
                    id="incoming_token_secret"
                    v-model="form.incoming_token_secret"
                    type="password"
                    class="font-mono"
                    autocomplete="new-password"
                />
            </FormField>
            <FormField
                label="Certificate fingerprint (SHA-256)"
                for="tls_fingerprint"
                :error="form.errors.tls_fingerprint"
                hint="Needed when the certificate of this cluster is not publicly trusted: the source cluster checks it."
            >
                <Input
                    id="tls_fingerprint"
                    v-model="form.tls_fingerprint"
                    class="font-mono text-xs"
                    autocomplete="off"
                />
            </FormField>
        </FormDialog>
    </section>
</template>
