import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import ReinstallLimitSection from '@/components/servers/ReinstallLimitSection.vue';
import type { ReinstallLimitState } from '@/types/admin';

const { submit, submitted, visit, replace } = vi.hoisted(() => ({
    submit: vi.fn(),
    submitted: { value: null as Record<string, unknown> | null },
    visit: vi.fn(),
    replace: (key: string, values: Record<string, string> = {}) =>
        Object.entries(values).reduce(
            (text, [name, value]) => text.replace(`:${name}`, value),
            key,
        ),
}));

vi.mock('laravel-vue-i18n', () => ({ trans: replace }));

vi.mock('@/composables/useDateTime', () => ({
    useDateTime: () => ({ formatDateTime: (iso: string) => iso }),
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        router: { visit },
        useForm: (data: Record<string, unknown>) => {
            let defaults = { ...data };
            let transform = (values: Record<string, unknown>) => values;
            const form: Record<string, unknown> = reactive({
                ...data,
                errors: {},
                processing: false,
                clearErrors: () => undefined,
                defaults: (values: Record<string, unknown>) => {
                    defaults = { ...values };
                },
                reset: () => Object.assign(form, defaults),
                transform: (callback: typeof transform) => {
                    transform = callback;

                    return form;
                },
                submit: (...args: unknown[]) => {
                    submitted.value = transform({ ...form });
                    submit(...args);
                },
            });

            return form;
        },
    };
});

const state = (overrides: Partial<ReinstallLimitState> = {}) => ({
    source: 'package' as const,
    limit: 3,
    period_days: 30,
    used: 1,
    remaining: 2,
    available_at: null,
    server_limit: null,
    server_period_days: null,
    package_limit: 3,
    package_period_days: 30,
    has_package: true,
    reset_at: null,
    ...overrides,
});

const mountSection = (limit: ReinstallLimitState) =>
    mount(ReinstallLimitSection, {
        props: {
            limit,
            action: { url: '/admin/servers/x/reinstall-limit', method: 'put' },
            resetAction: {
                url: '/admin/servers/x/reinstall-limit/reset',
                method: 'post',
            },
        },
        global: {
            mocks: { $t: replace },
            stubs: {
                FormDialog: {
                    props: ['title'],
                    emits: ['submit'],
                    template:
                        '<form @submit.prevent="$emit(\'submit\')"><slot /></form>',
                },
            },
        },
    });

describe('ReinstallLimitSection', () => {
    beforeEach(() => {
        submit.mockClear();
        visit.mockClear();
        submitted.value = null;
    });

    it('shows what the billing system has used and resets it', async () => {
        const wrapper = mountSection(state());

        expect(
            wrapper.find('[data-test="reinstall-limit-summary"]').text(),
        ).toBe('1 of 3 reinstalls used in the last 30 days.');

        await wrapper
            .find('[data-test="reinstall-limit-reset"]')
            .trigger('click');
        expect(visit).toHaveBeenCalledWith(
            {
                url: '/admin/servers/x/reinstall-limit/reset',
                method: 'post',
            },
            { preserveScroll: true },
        );
    });

    it('says when the next reinstall is possible', () => {
        const wrapper = mountSection(
            state({
                used: 3,
                remaining: 0,
                available_at: '2026-11-26T12:00:00+00:00',
            }),
        );

        expect(
            wrapper.find('[data-test="reinstall-limit-summary"]').text(),
        ).toContain(
            'The next reinstall is possible after 2026-11-26T12:00:00+00:00.',
        );
    });

    it('saves a limit of its own', async () => {
        const wrapper = mountSection(
            state({ limit: null, period_days: null, used: null }),
        );

        expect(
            wrapper.find('[data-test="reinstall-limit-reset"]').exists(),
        ).toBe(false);

        await wrapper
            .find('[data-test="reinstall-limit-edit"]')
            .trigger('click');
        await wrapper.find('#reinstall_limit_mode').setValue('server');
        await wrapper.find('#reinstall_limit').setValue('2');
        await wrapper.find('#reinstall_period_days').setValue('7');
        await wrapper.find('form').trigger('submit');

        expect(submit).toHaveBeenCalledTimes(1);
        expect(submitted.value).toEqual({
            custom: true,
            reinstall_limit: 2,
            reinstall_period_days: 7,
        });
    });

    it('goes back to the package limit', async () => {
        const wrapper = mountSection(
            state({ source: 'server', server_limit: 1, server_period_days: 7 }),
        );

        await wrapper
            .find('[data-test="reinstall-limit-edit"]')
            .trigger('click');
        await wrapper.find('#reinstall_limit_mode').setValue('package');
        await wrapper.find('form').trigger('submit');

        expect(submitted.value).toEqual({
            custom: false,
            reinstall_limit: null,
            reinstall_period_days: null,
        });
    });
});
