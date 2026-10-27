import { describe, expect, it } from 'vite-plus/test';
import { useMainNavigation } from '@/composables/useMainNavigation';
import { toUrl } from '@/lib/utils';

describe('useMainNavigation', () => {
    it('links to the admin pages', () => {
        const items = useMainNavigation().value;

        expect(items.map((item) => toUrl(item.href))).toEqual([
            '/admin',
            '/admin/servers',
            '/admin/locations',
            '/admin/clusters',
            '/admin/ip-pools',
            '/admin/dns-providers',
            '/admin/os-templates',
            '/admin/packages',
            '/admin/placement-groups',
            '/admin/firewall-rulesets',
            '/admin/apps',
            '/admin/reports',
            '/admin/drifts',
            '/admin/users',
            '/admin/billing-systems',
            '/admin/settings',
            '/admin/help',
        ]);
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
            '/admin/settings/network',
            '/admin/settings/usage',
            '/admin/settings/general',
            '/admin/settings/notifications',
            '/admin/settings/environment',
        ]);
    });
});
