<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes/admin';

/**
 * The panel's page for a request that failed: forbidden, not found, server
 * error or maintenance (`App\Http\ErrorPages`). Administrators get a way back
 * to the dashboard; anyone else (a customer on the console page) only Try
 * again.
 */
const props = defineProps<{ status: 403 | 404 | 500 | 503 }>();

// English keys, translated by the layout and below.
const texts = {
    403: {
        title: 'This is not allowed',
        description:
            'The panel refused this request. If it should be possible, check the state of what you opened.',
    },
    404: {
        title: 'Page not found',
        description:
            'This page or record does not exist, or it was deleted in the meantime.',
    },
    500: {
        title: 'Something went wrong',
        description:
            'The panel could not finish this request. The error was logged; try again, and look at the panel log if it keeps happening.',
    },
    503: {
        title: 'The panel is being updated',
        description:
            'It is in maintenance mode for a moment. This page works again once the update has finished.',
    },
} as const;

const text = computed(() => texts[props.status] ?? texts[500]);
const signedIn = computed(() => Boolean(usePage().props.auth?.user));

setLayoutProps({
    title: text.value.title,
    description: text.value.description,
});
</script>

<template>
    <Head :title="text.title" />

    <div class="flex flex-col items-center gap-4">
        <p
            class="font-mono text-sm text-muted-foreground tabular-nums"
            data-test="error-status"
        >
            {{ $t('Error :status', { status: String(status) }) }}
        </p>
        <div class="flex flex-wrap justify-center gap-2">
            <Button
                v-if="status === 500 || status === 503"
                variant="outline"
                data-test="error-retry"
                @click="router.reload()"
            >
                {{ $t('Try again') }}
            </Button>
            <Button v-if="signedIn" as-child data-test="error-dashboard">
                <Link :href="dashboard()">{{
                    $t('Back to the dashboard')
                }}</Link>
            </Button>
        </div>
    </div>
</template>
