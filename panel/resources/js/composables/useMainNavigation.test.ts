import { describe, expect, it } from 'vite-plus/test';
import {
    useMainNavigation,
    useMainNavigationGroups,
} from '@/composables/useMainNavigation';
import { toUrl } from '@/lib/utils';

describe('useMainNavigation', () => {
    it('links to the admin pages', () => {
        const items = useMainNavigation().value;

        expect(items.map((item) => toUrl(item.href))).toEqual([
            '/admin',
            '/admin/servers',
            '/admin/drifts',
            '/admin/reports',
            '/admin/locations',
            '/admin/clusters',
            '/admin/ip-pools',
            '/admin/placement-groups',
            '/admin/dns-providers',
            '/admin/os-templates',
            '/admin/packages',
            '/admin/firewall-rulesets',
            '/admin/apps',
            '/admin/billing-systems',
            '/admin/users',
            '/admin/settings',
            '/admin/help',
        ]);
    });

    it('groups daily work first, with at most five pages per group', () => {
        const groups = useMainNavigationGroups().value;

        expect(groups.map((group) => group.title)).toEqual([
            'Operate',
            'Infrastructure',
            'Catalog',
            'Administration',
        ]);
        expect(groups[0].items.map((item) => item.title)).toEqual([
            'Dashboard',
            'Servers',
            'Drift report',
            'Reports',
        ]);
        expect(
            Math.max(...groups.map((group) => group.items.length)),
        ).toBeLessThanOrEqual(5);
    });

    it('lists the reports under Reports', () => {
        const reports = useMainNavigation().value.find(
            (item) => item.title === 'Reports',
        );

        expect(reports?.items?.map((item) => toUrl(item.href))).toEqual([
            '/admin/reports/capacity',
            '/admin/reports/traffic',
            '/admin/reports/servers',
            '/admin/reports/operations',
        ]);
    });

    it('lists the pages of Panel settings under it', () => {
        const settings = useMainNavigation().value.find(
            (item) => item.title === 'Panel settings',
        );

        expect(settings?.items?.map((item) => toUrl(item.href))).toEqual([
            '/admin/settings/servers',
            '/admin/settings/backups',
            '/admin/settings/network',
            '/admin/settings/usage',
            '/admin/settings/general',
            '/admin/settings/notifications',
            '/admin/settings/branding',
            '/admin/settings/environment',
        ]);
    });
});
