import { usePage } from '@inertiajs/vue3';
import {
    Disc3,
    LayoutGrid,
    MapPin,
    Network,
    Package,
    Server,
} from '@lucide/vue';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { dashboard } from '@/routes';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as clusters } from '@/routes/admin/clusters';
import { index as ipPools } from '@/routes/admin/ip-pools';
import { index as locations } from '@/routes/admin/locations';
import { index as osTemplates } from '@/routes/admin/os-templates';
import { index as packages } from '@/routes/admin/packages';
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
                { title: 'Locations', href: locations(), icon: MapPin },
                { title: 'Clusters', href: clusters(), icon: Server },
                { title: 'IP pools', href: ipPools(), icon: Network },
                { title: 'OS templates', href: osTemplates(), icon: Disc3 },
                { title: 'Packages', href: packages(), icon: Package },
            ];
        }

        return [{ title: 'Dashboard', href: dashboard(), icon: LayoutGrid }];
    });
}
