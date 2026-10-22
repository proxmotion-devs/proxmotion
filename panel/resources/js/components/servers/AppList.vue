<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    CircleArrowUp,
    CircleStop,
    Eye,
    PackagePlus,
    Play,
    RotateCcw,
    ScrollText,
    Trash2,
} from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import AppInputFields from '@/components/servers/AppInputFields.vue';
import AppLogDialog from '@/components/servers/AppLogDialog.vue';
import AppOutputs from '@/components/servers/AppOutputs.vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import {
    action as actionRoute,
    cancel as cancelRoute,
    outputs as outputsRoute,
    retry,
    uninstall,
    upgrade as upgradeRoute,
} from '@/routes/admin/app-installations';
import { cancel as cancelRunRoute } from '@/routes/admin/app-runs';
import type {
    AppAction,
    AppInput,
    AppInstallation,
    AppInstallationStatus,
    AppOption,
    AppRun,
    AppRunStatus,
    AppText,
    RevealedAppOutputs,
    UnavailableApp,
} from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Apps installed on a server (docs/adr/0015) with their progress, their
 * actions, upgrade and uninstall (docs/adr/0016) with the latest runs, and a
 * dialog to install one of the apps that fit the server.
 */
const props = defineProps<{
    apps: AppInstallation[];
    options: AppOption[];
    /** Apps that do not fit the server, shown greyed out with the reason. */
    unavailable?: UnavailableApp[];
    action: RouteDefinition<'post'>;
    disabled?: boolean;
    /** What Show details revealed for one of the apps. */
    revealed?: RevealedAppOutputs | null;
}>();

const showOutputs = (app: AppInstallation) =>
    router.visit(outputsRoute(app.uuid), { preserveScroll: true });

const page = usePage<{ locale: string }>();
const { formatDateTime } = useDateTime();

// English keys, translated in the template.
const statusLabels: Record<AppInstallationStatus, string> = {
    queued: 'Queued',
    installing: 'Installing',
    installed: 'Installed',
    failed: 'Failed',
    uninstalling: 'Uninstalling',
    uninstall_failed: 'Uninstall failed',
    upgrading: 'Upgrading',
    upgrade_failed: 'Upgrade failed',
    removed: 'Removed',
};
const statusTone: Record<
    AppInstallationStatus,
    'success' | 'warning' | 'danger' | 'neutral'
> = {
    queued: 'neutral',
    installing: 'warning',
    installed: 'success',
    failed: 'danger',
    uninstalling: 'warning',
    uninstall_failed: 'danger',
    upgrading: 'warning',
    upgrade_failed: 'danger',
    removed: 'neutral',
};
const runStatusLabels: Record<AppRunStatus, string> = {
    queued: 'Queued',
    running: 'Running',
    succeeded: 'Succeeded',
    failed: 'Failed',
};
const runStatusTone: Record<
    AppRunStatus,
    'success' | 'warning' | 'danger' | 'neutral'
> = {
    queued: 'neutral',
    running: 'warning',
    succeeded: 'success',
    failed: 'danger',
};

const text = (value: AppText | null, fallback: string) =>
    value
        ? ((page.props.locale === 'tr' ? value.tr : null) ?? value.en)
        : fallback;

const runBusy = (app: AppInstallation) =>
    app.runs.some((run) => run.status === 'queued' || run.status === 'running');

const busy = computed(() =>
    props.apps.some(
        (app) =>
            ['queued', 'installing', 'uninstalling', 'upgrading'].includes(
                app.status,
            ) || runBusy(app),
    ),
);

const defaults = (inputs: AppInput[]) =>
    Object.fromEntries(
        inputs
            .filter((input) => input.default !== null)
            .map((input) => [
                input.key,
                input.default as string | number | boolean,
            ]),
    );

// Install
const open = ref(false);
const form = useForm<{
    app: string | null;
    inputs: Record<string, string | number | boolean>;
}>({ app: null, inputs: {} });

const selected = computed(() =>
    props.options.find((option) => option.slug === form.app),
);

// The apps that fit, then those that do not, disabled with their reason.
const appOptions = computed(() => [
    ...props.options.map((option) => ({
        value: option.slug,
        label: option.name,
    })),
    ...(props.unavailable ?? []).map((app) => ({
        value: app.slug,
        label: `${app.name}: ${app.reason}`,
        disabled: true,
    })),
]);

const resetInputs = () => {
    form.inputs = defaults(selected.value?.inputs ?? []);
};

const openDialog = () => {
    form.clearErrors();
    form.app = props.options[0]?.slug ?? null;
    resetInputs();
    open.value = true;
};

const submit = () =>
    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });

const retryInstallation = (app: AppInstallation) =>
    router.visit(retry(app.uuid), { preserveScroll: true });

// Actions
const actionOpen = ref(false);
const running = ref<{ app: AppInstallation; action: AppAction } | null>(null);
const actionForm = useForm<{
    inputs: Record<string, string | number | boolean>;
}>({ inputs: {} });

// Refusals of the action itself (not of one input), e.g. an unknown action.
const actionError = computed(
    () => (actionForm.errors as Record<string, string | undefined>).action,
);

const openAction = (app: AppInstallation, action: AppAction) => {
    running.value = { app, action };
    actionForm.clearErrors();
    actionForm.inputs = defaults(action.inputs);
    actionOpen.value = true;
};

const submitAction = () => {
    if (running.value === null) {
        return;
    }

    actionForm.submit(
        actionRoute({
            appInstallation: running.value.app.uuid,
            action: running.value.action.key,
        }),
        {
            preserveScroll: true,
            onSuccess: () => (actionOpen.value = false),
        },
    );
};

// Uninstall
const uninstallOpen = ref(false);
const removing = ref<AppInstallation | null>(null);
const uninstallForm = useForm({});

const openUninstall = (app: AppInstallation) => {
    removing.value = app;
    uninstallOpen.value = true;
};

const submitUninstall = () => {
    if (removing.value === null) {
        return;
    }

    uninstallForm.submit(uninstall(removing.value.uuid), {
        preserveScroll: true,
        onSuccess: () => (uninstallOpen.value = false),
    });
};

// Upgrade
const upgradeOpen = ref(false);
const upgrading = ref<AppInstallation | null>(null);
const upgradeForm = useForm<{
    inputs: Record<string, string | number | boolean>;
}>({ inputs: {} });

// Refusals of the upgrade itself, e.g. a version that no longer fits.
const upgradeError = computed(
    () => (upgradeForm.errors as Record<string, string | undefined>).upgrade,
);

const openUpgrade = (app: AppInstallation) => {
    upgrading.value = app;
    upgradeForm.clearErrors();
    upgradeForm.inputs = defaults(app.upgrade?.inputs ?? []);
    upgradeOpen.value = true;
};

const submitUpgrade = () => {
    if (upgrading.value === null) {
        return;
    }

    upgradeForm.submit(upgradeRoute(upgrading.value.uuid), {
        preserveScroll: true,
        onSuccess: () => (upgradeOpen.value = false),
    });
};

// Cancel a running installer or run (docs/adr/0030)
const cancelOpen = ref(false);
const cancelling = ref<{ app: AppInstallation; run: AppRun | null } | null>(
    null,
);
const cancelForm = useForm({});

const openCancel = (app: AppInstallation, run: AppRun | null = null) => {
    cancelling.value = { app, run };
    cancelOpen.value = true;
};

const submitCancel = () => {
    if (cancelling.value === null) {
        return;
    }

    const { app, run } = cancelling.value;

    cancelForm.submit(
        run === null ? cancelRoute(app.uuid) : cancelRunRoute(run.uuid),
        {
            preserveScroll: true,
            onSuccess: () => (cancelOpen.value = false),
        },
    );
};

const runLabel = (run: AppRun) => {
    if (run.kind === 'upgrade') {
        return trans('Upgrade to :version', {
            version: run.target_version ?? '',
        });
    }

    return run.kind === 'uninstall' ? trans('Uninstall') : text(run.action, '');
};

// Logs (docs/adr/0021)
const logOpen = ref(false);
const logHeading = ref('');
const logOptions = ref<{ value: string; label: string }[]>([]);

const openAppLog = (app: AppInstallation) => {
    logHeading.value = trans(':app log', { app: app.app });
    logOptions.value = app.logs.map((log) => ({
        value: log.uuid,
        label: trans('Attempt :attempt, :time', {
            attempt: String(log.attempt ?? 1),
            time: formatDateTime(log.created_at),
        }),
    }));
    logOpen.value = true;
};

const openRunLog = (app: AppInstallation, run: AppRun) => {
    if (run.log === null) {
        return;
    }

    logHeading.value = `${app.app}: ${runLabel(run)}`;
    logOptions.value = [{ value: run.log, label: runLabel(run) }];
    logOpen.value = true;
};
</script>

<template>
    <div class="flex flex-col gap-3">
        <div>
            <Button
                variant="outline"
                :disabled="disabled || busy || options.length === 0"
                @click="openDialog"
            >
                <PackagePlus class="h-4 w-4" />
                {{ $t('Install app') }}
            </Button>
        </div>

        <details
            v-if="unavailable?.length"
            class="text-sm text-muted-foreground"
            data-test="unavailable-apps"
        >
            <summary class="cursor-pointer select-none">
                {{
                    $t('Not available on this server (:count)', {
                        count: String(unavailable.length),
                    })
                }}
            </summary>
            <ul class="mt-2 flex flex-col gap-1 pl-4">
                <li
                    v-for="app in unavailable"
                    :key="app.slug"
                    class="opacity-70"
                >
                    <span class="font-medium">{{ app.name }}</span
                    >:
                    {{ app.reason }}
                </li>
            </ul>
        </details>

        <div v-if="apps.length" class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('App') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Progress') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Started') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <template v-for="app in apps" :key="app.uuid">
                        <tr class="border-t align-top">
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ app.app }}</span>
                                <span
                                    class="ml-1 text-xs text-muted-foreground"
                                    >{{ app.version }}</span
                                >
                                <StatusBadge
                                    v-if="app.is_trial"
                                    class="ml-1"
                                    tone="warning"
                                    label="Trial"
                                />
                                <ul
                                    v-if="app.runs.length"
                                    class="mt-2 flex flex-col gap-1 text-xs text-muted-foreground"
                                    data-test="app-runs"
                                >
                                    <li
                                        v-for="run in app.runs"
                                        :key="run.uuid"
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span>{{ runLabel(run) }}</span>
                                        <StatusBadge
                                            :tone="runStatusTone[run.status]"
                                            :label="runStatusLabels[run.status]"
                                        />
                                        <span>{{
                                            formatDateTime(run.created_at)
                                        }}</span>
                                        <button
                                            v-if="run.status === 'running'"
                                            type="button"
                                            class="text-destructive underline underline-offset-2 hover:text-destructive/80"
                                            data-test="app-run-cancel"
                                            @click="openCancel(app, run)"
                                        >
                                            {{ $t('Cancel') }}
                                        </button>
                                        <button
                                            v-if="run.log"
                                            type="button"
                                            class="underline underline-offset-2 hover:text-foreground"
                                            data-test="app-run-log"
                                            @click="openRunLog(app, run)"
                                        >
                                            {{ $t('Log') }}
                                        </button>
                                        <span
                                            v-if="run.error"
                                            class="w-full break-words text-red-600 dark:text-red-400"
                                            >{{ run.error }}</span
                                        >
                                    </li>
                                </ul>
                            </td>
                            <td class="px-4 py-3">
                                <StatusBadge
                                    :tone="statusTone[app.status]"
                                    :label="statusLabels[app.status]"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="app.status === 'installing'"
                                    >{{ app.progress }}% · </span
                                >{{ app.step ?? '' }}
                                <p
                                    v-if="app.error"
                                    class="mt-1 text-xs break-words text-red-600 dark:text-red-400"
                                >
                                    {{ app.error }}
                                </p>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatDateTime(app.created_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <Button
                                        v-if="app.logs.length"
                                        variant="outline"
                                        size="sm"
                                        data-test="app-log"
                                        @click="openAppLog(app)"
                                    >
                                        <ScrollText class="h-4 w-4" />
                                        {{
                                            app.status === 'installing'
                                                ? $t('Watch')
                                                : $t('Log')
                                        }}
                                    </Button>
                                    <Button
                                        v-if="app.has_outputs"
                                        variant="outline"
                                        size="sm"
                                        data-test="app-show-outputs"
                                        @click="showOutputs(app)"
                                    >
                                        <Eye class="h-4 w-4" />
                                        {{ $t('Show details') }}
                                    </Button>
                                    <Button
                                        v-for="appAction in app.actions"
                                        :key="appAction.key"
                                        variant="outline"
                                        size="sm"
                                        :disabled="disabled || runBusy(app)"
                                        @click="openAction(app, appAction)"
                                    >
                                        <Play class="h-4 w-4" />
                                        {{
                                            text(appAction.label, appAction.key)
                                        }}
                                    </Button>
                                    <Button
                                        v-if="app.upgrade"
                                        variant="outline"
                                        size="sm"
                                        :disabled="disabled || busy"
                                        data-test="app-upgrade"
                                        @click="openUpgrade(app)"
                                    >
                                        <CircleArrowUp class="h-4 w-4" />
                                        {{
                                            $t('Upgrade to :version', {
                                                version: app.upgrade.version,
                                            })
                                        }}
                                    </Button>
                                    <Button
                                        v-if="app.status === 'installing'"
                                        variant="outline"
                                        size="sm"
                                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        data-test="app-cancel"
                                        @click="openCancel(app)"
                                    >
                                        <CircleStop class="h-4 w-4" />
                                        {{ $t('Cancel') }}
                                    </Button>
                                    <Button
                                        v-if="app.status === 'failed'"
                                        variant="outline"
                                        size="sm"
                                        :disabled="disabled || busy"
                                        @click="retryInstallation(app)"
                                    >
                                        <RotateCcw class="h-4 w-4" />
                                        {{ $t('Retry') }}
                                    </Button>
                                    <Button
                                        v-if="app.can_uninstall"
                                        variant="outline"
                                        size="sm"
                                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        :disabled="disabled || busy"
                                        data-test="app-uninstall"
                                        @click="openUninstall(app)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                        {{ $t('Uninstall') }}
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-if="
                                revealed && revealed.installation === app.uuid
                            "
                            class="bg-muted/30"
                        >
                            <td colspan="5" class="px-4 py-3">
                                <AppOutputs :revealed="revealed" />
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            {{ $t('No apps installed.') }}
        </p>
    </div>

    <FormDialog
        v-model:open="open"
        title="Install app"
        description="The app is installed inside the server through the guest agent. This can take several minutes; the progress shows here."
        submit-label="Install"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField label="App" for="app" :error="form.errors.app">
            <NativeSelect
                id="app"
                v-model="form.app"
                :options="appOptions"
                @change="resetInputs"
            />
        </FormField>

        <AppInputFields
            v-model="form.inputs"
            :inputs="selected?.inputs ?? []"
            :errors="form.errors as Record<string, string>"
            id-prefix="app_input"
        />
    </FormDialog>

    <FormDialog
        v-model:open="actionOpen"
        :title="running ? text(running.action.label, running.action.key) : ''"
        description="The action runs inside the server through the guest agent. Its outcome shows in the app's list; files it creates are handed to the billing system."
        submit-label="Run"
        :processing="actionForm.processing"
        @submit="submitAction"
    >
        <p v-if="actionError" class="text-sm text-red-600 dark:text-red-500">
            {{ actionError }}
        </p>
        <AppInputFields
            v-model="actionForm.inputs"
            :inputs="running?.action.inputs ?? []"
            :errors="actionForm.errors as Record<string, string>"
            id-prefix="app_action_input"
        />
    </FormDialog>

    <FormDialog
        v-model:open="upgradeOpen"
        title="Upgrade app"
        description="The new version's upgrade script runs inside the server and keeps the app's data and settings. If it fails, the app keeps its current version and the upgrade can be tried again."
        submit-label="Upgrade"
        :processing="upgradeForm.processing"
        @submit="submitUpgrade"
    >
        <p class="text-sm font-medium">
            {{ upgrading?.app }} {{ upgrading?.version }} →
            {{ upgrading?.upgrade?.version }}
        </p>
        <p v-if="upgradeError" class="text-sm text-red-600 dark:text-red-500">
            {{ upgradeError }}
        </p>
        <AppInputFields
            v-model="upgradeForm.inputs"
            :inputs="upgrading?.upgrade?.inputs ?? []"
            :errors="upgradeForm.errors as Record<string, string>"
            id-prefix="app_upgrade_input"
        />
    </FormDialog>

    <FormDialog
        v-model:open="uninstallOpen"
        title="Uninstall app"
        description="The app's uninstall script runs inside the server and removes the app and its data as the app describes. Its outputs (VPN profiles and the like) stop working. This cannot be undone."
        submit-label="Uninstall"
        :processing="uninstallForm.processing"
        @submit="submitUninstall"
    >
        <p class="text-sm font-medium">{{ removing?.app }}</p>
    </FormDialog>

    <FormDialog
        v-model:open="cancelOpen"
        :title="cancelling?.run ? 'Cancel script' : 'Cancel installation'"
        description="The script is stopped inside the server where it is, nothing it did is undone: the app may be left half installed. Retry or uninstall the app afterwards, or reinstall the server."
        submit-label="Stop script"
        :processing="cancelForm.processing"
        @submit="submitCancel"
    >
        <p class="text-sm font-medium">
            {{ cancelling?.app.app }}
            <template v-if="cancelling?.run">
                · {{ runLabel(cancelling.run) }}</template
            >
        </p>
    </FormDialog>

    <AppLogDialog
        v-model:open="logOpen"
        :heading="logHeading"
        :logs="logOptions"
    />
</template>
