<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import ReinstallLimitFields from '@/components/servers/ReinstallLimitFields.vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { optionalNumber } from '@/lib/forms';
import type { OptionalNumber } from '@/lib/forms';
import type { ReinstallLimitState } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * How often the server's billing system may reinstall it, its package's
 * limit or its own, and resetting what it has used (docs/adr/0045).
 * Administrators are never limited.
 */
const props = defineProps<{
    limit: ReinstallLimitState;
    action: RouteDefinition<'put'>;
    resetAction: RouteDefinition<'post'>;
}>();

const { formatDateTime } = useDateTime();

const describe = (count: number | null, days: number | null): string =>
    count === null || days === null
        ? trans('unlimited')
        : count === 0
          ? trans('none')
          : trans(':count in :days days', {
                count: String(count),
                days: String(days),
            });

const summary = computed(() => {
    const { limit, period_days: days, used } = props.limit;

    if (limit === null || days === null) {
        return trans(
            'The billing system may reinstall this server without a limit.',
        );
    }

    if (limit === 0) {
        return trans('The billing system may not reinstall this server.');
    }

    return trans(':used of :limit reinstalls used in the last :days days.', {
        used: String(used ?? 0),
        limit: String(limit),
        days: String(days),
    });
});

const sourceLabel = computed(() =>
    props.limit.source === 'server'
        ? trans('Set for this server')
        : props.limit.has_package
          ? trans("The package's limit")
          : trans('No package: unlimited'),
);

const open = ref(false);
const form = useForm({
    custom: 'package' as 'package' | 'server',
    reinstall_limit: '' as OptionalNumber,
    reinstall_period_days: 30 as OptionalNumber,
});

const modeOptions = computed(() => [
    {
        value: 'package' as const,
        label: props.limit.has_package
            ? trans("Use the package's limit (:limit)", {
                  limit: describe(
                      props.limit.package_limit,
                      props.limit.package_period_days,
                  ),
              })
            : trans('No package: unlimited'),
    },
    { value: 'server' as const, label: trans('Set a limit for this server') },
]);

const openDialog = () => {
    form.clearErrors();
    form.defaults({
        custom: props.limit.source,
        reinstall_limit: props.limit.server_limit ?? '',
        reinstall_period_days: props.limit.server_period_days ?? 30,
    });
    form.reset();
    open.value = true;
};

const submit = () =>
    form
        .transform((data) => ({
            custom: data.custom === 'server',
            reinstall_limit:
                data.custom === 'server'
                    ? optionalNumber(data.reinstall_limit)
                    : null,
            reinstall_period_days:
                data.custom === 'server' && data.reinstall_limit !== ''
                    ? optionalNumber(data.reinstall_period_days)
                    : null,
        }))
        .submit(props.action, {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });

const reset = () => router.visit(props.resetAction, { preserveScroll: true });
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border p-4"
        data-test="reinstall-limit"
    >
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="font-medium">{{ $t('Reinstall limit') }}</h3>
                <p class="text-xs text-muted-foreground">
                    {{ sourceLabel }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="limit.used"
                    variant="outline"
                    size="sm"
                    data-test="reinstall-limit-reset"
                    @click="reset"
                >
                    {{ $t('Reset count') }}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    data-test="reinstall-limit-edit"
                    @click="openDialog"
                >
                    {{ $t('Change') }}
                </Button>
            </div>
        </div>

        <p class="text-sm" data-test="reinstall-limit-summary">
            {{ summary }}
            <template
                v-if="
                    limit.limit && limit.remaining === 0 && limit.available_at
                "
            >
                {{
                    $t('The next reinstall is possible after :time.', {
                        time: formatDateTime(limit.available_at),
                    })
                }}
            </template>
        </p>
        <p class="text-xs text-muted-foreground">
            {{
                $t(
                    'Only reinstalls through the billing system count, and failed ones do not. Administrators can always reinstall.',
                )
            }}
            <template v-if="limit.reset_at">
                {{
                    $t('Counted since :time.', {
                        time: formatDateTime(limit.reset_at),
                    })
                }}
            </template>
        </p>

        <FormDialog
            v-model:open="open"
            title="Reinstall limit"
            description="How often the billing system may reinstall this server."
            :processing="form.processing"
            @submit="submit"
        >
            <FormField
                label="Limit"
                for="reinstall_limit_mode"
                :error="form.errors.custom"
            >
                <NativeSelect
                    id="reinstall_limit_mode"
                    v-model="form.custom"
                    :options="modeOptions"
                />
            </FormField>
            <ReinstallLimitFields
                v-if="form.custom === 'server'"
                v-model:limit="form.reinstall_limit"
                v-model:days="form.reinstall_period_days"
                :errors="form.errors"
            />
        </FormDialog>
    </section>
</template>
