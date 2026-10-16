import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { useMainNavigation } from '@/composables/useMainNavigation';
import { toUrl } from '@/lib/utils';

const page = { props: { auth: { user: { role: 'client' } } } };

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
}));

describe('useMainNavigation', () => {
    beforeEach(() => {
        page.props.auth.user.role = 'client';
    });

    it('links clients to their own dashboard', () => {
        const items = useMainNavigation().value;

        expect(items.map((item) => toUrl(item.href))).toEqual(['/dashboard']);
    });

    it('links admins to the admin dashboard', () => {
        page.props.auth.user.role = 'admin';

        const items = useMainNavigation().value;

        expect(items.map((item) => toUrl(item.href))).toEqual([
            '/admin',
            '/admin/servers',
            '/admin/users',
            '/admin/locations',
            '/admin/clusters',
            '/admin/ip-pools',
            '/admin/os-templates',
            '/admin/packages',
            '/admin/drifts',
        ]);
    });
});
