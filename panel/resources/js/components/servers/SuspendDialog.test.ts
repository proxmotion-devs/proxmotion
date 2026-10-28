import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import SuspendDialog from '@/components/servers/SuspendDialog.vue';

const { submit, submitted } = vi.hoisted(() => ({
    submit: vi.fn(),
    submitted: { value: null as Record<string, unknown> | null },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        useForm: (data: Record<string, unknown>) => {
            const form: Record<string, unknown> = reactive({
                ...data,
                errors: {},
                processing: false,
                clearErrors: () => undefined,
                reset: () => Object.assign(form, data),
                submit: (...args: unknown[]) => {
                    submitted.value = { reason: form.reason };
                    submit(...args);
                },
            });

            return form;
        },
    };
});

const mountDialog = (takeOver = false) =>
    mount(SuspendDialog, {
        props: {
            action: { url: '/admin/servers/x/suspend', method: 'post' },
            takeOver,
        },
        global: {
            mocks: { $t: (key: string) => key },
            stubs: {
                FormDialog: {
                    props: ['title', 'description', 'submitLabel'],
                    emits: ['submit'],
                    template:
                        '<form :data-title="title" :data-description="description" @submit.prevent="$emit(\'submit\')"><slot /></form>',
                },
            },
        },
    });

describe('SuspendDialog', () => {
    beforeEach(() => {
        submit.mockClear();
        submitted.value = null;
    });

    it('suspends with the reason typed', async () => {
        const wrapper = mountDialog();

        expect(wrapper.find('[data-test="suspend"]').text()).toContain(
            'Suspend',
        );

        await wrapper.find('[data-test="suspend"]').trigger('click');
        await wrapper.find('#suspension_reason').setValue('Overdue invoice');
        await wrapper.find('form').trigger('submit');

        expect(submit).toHaveBeenCalledTimes(1);
        expect(submit.mock.calls[0][0]).toEqual({
            url: '/admin/servers/x/suspend',
            method: 'post',
        });
        expect(submitted.value).toEqual({ reason: 'Overdue invoice' });
    });

    it('keeps a suspension by the panel', () => {
        const wrapper = mountDialog(true);

        expect(wrapper.find('[data-test="suspend"]').text()).toContain(
            'Keep suspended',
        );
        expect(wrapper.find('form').attributes('data-title')).toBe(
            'Keep the server suspended',
        );
        expect(wrapper.find('form').attributes('data-description')).toContain(
            'The panel suspended this server itself.',
        );
    });
});
