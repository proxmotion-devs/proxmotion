import {
    AppWindow,
    Boxes,
    Disc3,
    Globe,
    KeyRound,
    LayoutGrid,
    MapPin,
    Network,
    Package,
    Server,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { dashboard } from '@/routes/admin';
import { index as apps } from '@/routes/admin/apps';
import { index as billingSystems } from '@/routes/admin/billing-systems';
import { index as clusters } from '@/routes/admin/clusters';
import { index as dnsProviders } from '@/routes/admin/dns-providers';
import { index as drifts } from '@/routes/admin/drifts';
import { index as ipPools } from '@/routes/admin/ip-pools';
import { index as locations } from '@/routes/admin/locations';
import { index as osTemplates } from '@/routes/admin/os-templates';
import { index as packages } from '@/routes/admin/packages';
import { index as servers } from '@/routes/admin/servers';
import { index as users } from '@/routes/admin/users';
import type { NavItem } from '@/types';

/**
 * Main navigation. Every panel user is an administrator (docs/adr/0007).
 * Titles are English keys, translated by the navigation components.
 */
export function useMainNavigation(): ComputedRef<NavItem[]> {
    return computed(() => [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        { title: 'Servers', href: servers(), icon: Server },
        { title: 'Locations', href: locations(), icon: MapPin },
        { title: 'Clusters', href: clusters(), icon: Boxes },
        { title: 'IP pools', href: ipPools(), icon: Network },
        { title: 'DNS providers', href: dnsProviders(), icon: Globe },
        { title: 'OS templates', href: osTemplates(), icon: Disc3 },
        { title: 'Packages', href: packages(), icon: Package },
        { title: 'Apps', href: apps(), icon: AppWindow },
        { title: 'Drift report', href: drifts(), icon: TriangleAlert },
        { title: 'Administrators', href: users(), icon: Users },
        { title: 'Billing systems', href: billingSystems(), icon: KeyRound },
    ]);
}
