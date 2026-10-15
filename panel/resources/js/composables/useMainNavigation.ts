import { usePage } from '@inertiajs/vue3';
import { LayoutGrid } from '@lucide/vue';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { dashboard } from '@/routes';
import { dashboard as adminDashboard } from '@/routes/admin';
import type { NavItem } from '@/types';

/**
 * Main navigation for the signed-in user. Titles are English keys,
 * translated by the navigation components.
 */
export function useMainNavigation(): ComputedRef<NavItem[]> {
    const page = usePage();

    return computed(() => {
        if (page.props.auth.user.role === 'admin') {
            return [
                {
                    title: 'Dashboard',
                    href: adminDashboard(),
                    icon: LayoutGrid,
                },
            ];
        }

        return [
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutGrid,
            },
        ];
    });
}
