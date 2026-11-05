import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { nextTick, reactive } from 'vue';
import OsSystems from '@/components/admin/OsSystems.vue';
import type { OperatingSystem } from '@/lib/osTemplates';

const { visit, replace } = vi.hoisted(() => ({
    visit: vi.fn(),
    replace: (key: string, params: Record<string, string> = {}) =>
        Object.entries(params).reduce(
            (text, [name, value]) => text.replace(`:${name}`, value),
            key,
        ),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit },
    useForm: (data: Record<string, string>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            clearErrors: () => {},
            defaults: () => {},
            reset: () => {},
            submit: () => {},
        }),
}));

vi.mock('laravel-vue-i18n', () => ({ trans: replace }));

const ubuntu: OperatingSystem = {
    id: 1,
    name: 'Ubuntu',
    platform: 'linux',
    apps: 3,
    versions: [
        { id: 11, version: '24.04', templates: 0, apps: 2 },
        { id: 12, version: '22.04', templates: 1, apps: 1 },
        { id: 13, version: '20.04', templates: 0, apps: 0 },
    ],
};

const mountSystems = () =>
    mount(OsSystems, {
        props: { systems: [ubuntu] },
        global: { mocks: { $t: replace } },
        attachTo: document.body,
    });

const find = (selector: string) =>
    document.body.querySelector<HTMLButtonElement>(selector);

describe('OsSystems', () => {
    afterEach(() => {
        visit.mockClear();
        document.body.innerHTML = '';
    });

    it('asks before deleting a version and says how many apps lose it', async () => {
        const wrapper = mountSystems();

        find('[data-test="remove-version-11"]')!.click();
        await nextTick();

        // Nothing is deleted by the click itself.
        expect(visit).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain(
            'Ubuntu 24.04 is deleted and 2 apps are no longer offered on it.',
        );

        find('[data-test="confirm-dialog-confirm"]')!.click();
        await nextTick();

        expect(visit).toHaveBeenCalledOnce();
        expect(visit.mock.calls[0][0].url).toContain(
            '/operating-systems/1/versions/11',
        );
        wrapper.unmount();
    });

    it('says when nothing uses the version', async () => {
        const wrapper = mountSystems();

        find('[data-test="remove-version-13"]')!.click();
        await nextTick();

        expect(document.body.textContent).toContain(
            'Ubuntu 20.04 is deleted. No OS template or app uses it.',
        );
        wrapper.unmount();
    });

    it('offers no delete for a version a template uses, and says why', () => {
        const wrapper = mountSystems();

        expect(find('[data-test="remove-version-12"]')).toBeNull();
        expect(wrapper.text()).toContain(
            'Versions an OS template uses cannot be deleted.',
        );
        wrapper.unmount();
    });
});
