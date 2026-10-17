<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index, update } from '@/routes/admin/apps';
import type { AppText } from '@/types/admin';

/**
 * App recipes synced from panel/recipes with `php artisan apps:sync`
 * (docs/adr/0015). Inactive apps are neither listed nor installable.
 */
defineOptions({
    layout: { breadcrumbs: [{ title: 'Apps', href: index() }] },
});

defineProps<{
    apps: {
        slug: string;
        name: string;
        version: string;
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
</script>

<template>
    <Head title="Apps" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Apps"
            description="Apps customers can install on their servers through the billing system. New and updated recipes are added with php artisan apps:sync."
        />

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
                                app.version
                            }}</span>
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
                        <td class="px-4 py-3 text-right">
                            <Button
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
</template>
