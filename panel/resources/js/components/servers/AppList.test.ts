import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import AppList from '@/components/servers/AppList.vue';
import type { AppInstallation, AppRun } from '@/types/admin';

const { submit } = vi.hoisted(() => ({ submit: vi.fn() }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        router: { visit: vi.fn() },
        usePage: () => ({ props: { locale: 'en' } }),
        useForm: (data: Record<string, unknown>) =>
            reactive({
                ...data,
                errors: {},
                processing: false,
                clearErrors: () => undefined,
                submit,
            }),
    };
});

vi.mock('laravel-vue-i18n', () => ({ trans: (key: string) => key }));

const run = (overrides: Partial<AppRun> = {}): AppRun => ({
    uuid: 'run-uuid',
    kind: 'action',
    action: { en: 'Add a client' },
    target_version: null,
    status: 'running',
    step: null,
    error: null,
    log: null,
    created_at: null,
    ...overrides,
});

const installation = (
    overrides: Partial<AppInstallation> = {},
): AppInstallation => ({
    uuid: 'installation-uuid',
    app: 'WireGuard',
    version: '1.3.0',
    is_trial: false,
    status: 'installing',
    progress: 40,
    step: 'Installing WireGuard',
    error: null,
    created_at: null,
    finished_at: null,
    can_uninstall: false,
    actions: [],
    upgrade: null,
    runs: [],
    logs: [],
    has_outputs: false,
    ...overrides,
});

const mountList = (apps: AppInstallation[]) =>
    mount(AppList, {
        props: {
            apps,
            options: [],
            action: { url: '/admin/servers/x/apps', method: 'post' },
            // Cancel stays possible while the server is not active.
            disabled: true,
        },
        global: {
            mocks: { $t: (key: string) => key },
            stubs: {
                AppLogDialog: true,
                FormDialog: {
                    props: ['open', 'title'],
                    emits: ['submit'],
                    template:
                        '<form v-if="open" :data-title="title" @submit.prevent="$emit(\'submit\')"><slot /></form>',
                },
            },
        },
    });

describe('AppList', () => {
    beforeEach(() => submit.mockReset());

    it('cancels an installing app after a confirmation', async () => {
        const wrapper = mountList([installation()]);

        await wrapper.find('[data-test="app-cancel"]').trigger('click');
        await flushPromises();

        const dialog = wrapper.find('form[data-title="Cancel installation"]');
        expect(dialog.exists()).toBe(true);
        await dialog.trigger('submit');

        expect(submit).toHaveBeenCalledOnce();
        expect(submit.mock.calls[0][0].url).toContain(
            '/admin/app-installations/installation-uuid/cancel',
        );
    });

    it('cancels a running run', async () => {
        const wrapper = mountList([
            installation({ status: 'installed', runs: [run()] }),
        ]);

        expect(wrapper.find('[data-test="app-cancel"]').exists()).toBe(false);
        await wrapper.find('[data-test="app-run-cancel"]').trigger('click');
        await flushPromises();
        await wrapper
            .find('form[data-title="Cancel script"]')
            .trigger('submit');

        expect(submit.mock.calls[0][0].url).toContain(
            '/admin/app-runs/run-uuid/cancel',
        );
    });

    it('offers no cancel once a script has finished', () => {
        const wrapper = mountList([
            installation({
                status: 'failed',
                runs: [run({ status: 'succeeded' })],
            }),
        ]);

        expect(wrapper.find('[data-test="app-cancel"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="app-run-cancel"]').exists()).toBe(
            false,
        );
    });
});
