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
            '/admin/apps',
            '/admin/drifts',
            '/admin/users',
            '/admin/billing-systems',
        ]);
    });
});
