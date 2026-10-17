<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { edit, index, store, update } from '@/routes/admin/apps';
import type { AppText } from '@/types/admin';

/**
 * Apps: bundled recipes synced from panel/recipes with `php artisan apps:sync`
 * (docs/adr/0015) and custom apps written in the editor (docs/adr/0018).
 * Inactive apps are neither listed nor installable.
 */
defineOptions({
    layout: { breadcrumbs: [{ title: 'Apps', href: index() }] },
});

defineProps<{
    apps: {
        slug: string;
        name: string;
        source: 'bundled' | 'custom';
        /** A bundled app an administrator edited in place. */
        modified: boolean;
        published: boolean;
        draft_version: string | null;
        version: string | null;
        category: string;
        description: AppText;
        os: string[];
        billing: 'free' | 'paid';
        is_active: boolean;
        installed_count: number;
    }[];
}>();

const page = usePage<{ locale: string }>();

const describe = (text: AppText) =>
    (page.props.locale === 'tr' ? text.tr : null) ?? text.en;

const toggle = (slug: string, active: boolean) =>
    router.visit(update(slug), {
        data: { is_active: active },
        preserveScroll: true,
    });

const createOpen = ref(false);
const createForm = useForm({ slug: '', name: '' });

const submitCreate = () =>
    createForm.submit(store(), {
        onSuccess: () => {
            const slug = createForm.slug;
            createOpen.value = false;
            router.visit(edit(slug));
        },
    });
</script>

<template>
    <Head title="Apps" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Apps"
                description="Apps customers can install on their servers through the billing system. Bundled apps come with the panel (php artisan apps:sync) and can be edited here; panel updates keep your changes. You can also write your own."
            />
            <Button
                data-test="new-app"
                @click="
                    createForm.reset();
                    createForm.clearErrors();
                    createOpen = true;
                "
            >
                <Plus class="h-4 w-4" />
                {{ $t('New app') }}
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('App') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Operating systems') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Installed') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="app in apps" :key="app.slug" class="border-t">
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ app.name }}</span>
                            <span class="ml-1 text-xs text-muted-foreground">{{
                                app.version ?? ''
                            }}</span>
                            <StatusBadge
                                v-if="app.source === 'custom'"
                                class="ml-2"
                                tone="neutral"
                                label="Custom"
                            />
                            <StatusBadge
                                v-if="app.modified"
                                class="ml-2"
                                tone="warning"
                                label="Modified"
                            />
                            <StatusBadge
                                v-if="app.draft_version"
                                class="ml-2"
                                tone="warning"
                                label="Draft"
                            />
                            <StatusBadge
                                v-if="!app.is_active"
                                tone="neutral"
                                label="Inactive"
                                class="ml-2"
                            />
                            <StatusBadge
                                v-if="app.billing === 'paid'"
                                tone="warning"
                                label="Paid"
                                class="ml-2"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ describe(app.description) }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <div v-for="os in app.os" :key="os">{{ os }}</div>
                        </td>
                        <td class="px-4 py-3">{{ app.installed_count }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <Button
                                as-child
                                variant="outline"
                                size="sm"
                                class="mr-2"
                            >
                                <Link :href="edit(app.slug)">
                                    <Pencil class="h-4 w-4" />
                                    {{ $t('Edit') }}
                                </Link>
                            </Button>
                            <Button
                                v-if="app.published"
                                variant="outline"
                                size="sm"
                                @click="toggle(app.slug, !app.is_active)"
                            >
                                {{
                                    app.is_active
                                        ? $t('Deactivate')
                                        : $t('Activate')
                                }}
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="apps.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{
                                $t(
                                    'No apps yet. Run php artisan apps:sync to add the bundled recipes.',
                                )
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <FormDialog
        v-model:open="createOpen"
        title="New app"
        description="A custom app starts as a draft made from the recipe skeleton. It is offered to customers once you publish it."
        submit-label="Create"
        :processing="createForm.processing"
        @submit="submitCreate"
    >
        <FormField
            label="Slug"
            for="new_slug"
            hint="Lower-case letters, digits and dashes, e.g. uptime-kuma. It cannot be changed later."
            :error="createForm.errors.slug"
        >
            <Input id="new_slug" v-model="createForm.slug" class="font-mono" />
        </FormField>
        <FormField label="Name" for="new_name" :error="createForm.errors.name">
            <Input id="new_name" v-model="createForm.name" />
        </FormField>
    </FormDialog>
</template>
