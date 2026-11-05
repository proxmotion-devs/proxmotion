import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import SnapshotsSection from '@/components/servers/SnapshotsSection.vue';

const { submit, submitted } = vi.hoisted(() => ({
    submit: vi.fn(),
    submitted: { value: null as Record<string, unknown> | null },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        useForm: (initial: Record<string, unknown>) => {
            const form: Record<string, unknown> = reactive({
                ...initial,
                errors: {},
                processing: false,
                reset: () => Object.assign(form, initial),
                clearErrors: () => {
                    form.errors = {};
                },
                submit: (
                    route: unknown,
                    options: { onSuccess?: () => void } = {},
                ) => {
                    submitted.value = { ...form };
                    submit(route, options);
                    options.onSuccess?.();
                },
            });

            return form;
        },
    };
});

vi.mock('@/composables/useDateTime', () => ({
    useDateTime: () => ({ formatDateTime: (iso: string) => iso }),
}));

vi.mock('@/routes/admin/servers/snapshots', () => ({
    store: (server: string) => ({
        url: `/admin/servers/${server}/snapshots`,
        method: 'post',
    }),
    rollback: (args: { server: string; snapshot: string }) => ({
        url: `/admin/servers/${args.server}/snapshots/${args.snapshot}/rollback`,
        method: 'post',
    }),
    destroy: (args: { server: string; snapshot: string }) => ({
        url: `/admin/servers/${args.server}/snapshots/${args.snapshot}`,
        method: 'delete',
    }),
}));

const mountSection = () =>
    mount(SnapshotsSection, {
        props: {
            server: 'server-uuid',
            hostname: 'vps1.example.com',
            snapshots: {
                items: [
                    {
                        id: 'snap-1',
                        label: 'Before upgrade',
                        created_at: '2026-11-04T10:00:00Z',
                    },
                ],
                slots: 2,
                unavailable: null,
            },
        },
        global: {
            mocks: {
                $t: (key: string, replace: Record<string, string> = {}) =>
                    Object.entries(replace).reduce(
                        (text, [name, value]) =>
                            text.replace(`:${name}`, value),
                        key,
                    ),
            },
            stubs: {
                ConfirmDelete: true,
                FormDialog: {
                    props: {
                        title: String,
                        submitDisabled: Boolean,
                        destructive: Boolean,
                    },
                    emits: ['submit'],
                    template:
                        '<form :data-dialog="title" :data-submit-disabled="String(!!submitDisabled)" :data-destructive="String(!!destructive)" @submit.prevent="$emit(\'submit\')"><slot /></form>',
                },
            },
        },
    });

describe('SnapshotsSection', () => {
    beforeEach(() => {
        submit.mockClear();
        submitted.value = null;
    });

    it('marks Roll back as destructive and locks it until the hostname matches', async () => {
        const wrapper = mountSection();
        const trigger = wrapper.find('[data-test="rollback-snapshot"]');

        expect(trigger.classes()).toContain('text-destructive');
        await trigger.trigger('click');

        const dialog = () =>
            wrapper.find('form[data-dialog="Roll back to snapshot"]');
        expect(dialog().attributes('data-destructive')).toBe('true');
        expect(dialog().attributes('data-submit-disabled')).toBe('true');
        expect(
            dialog().find('[data-test="confirm-hostname-target"]').text(),
        ).toContain('vps1.example.com');

        await wrapper
            .find('#snapshot_confirm_hostname')
            .setValue('vps1.example.com');
        expect(dialog().attributes('data-submit-disabled')).toBe('false');

        await dialog().trigger('submit');
        expect(submitted.value?.confirm_hostname).toBe('vps1.example.com');
        expect(submit).toHaveBeenCalledWith(
            {
                url: '/admin/servers/server-uuid/snapshots/snap-1/rollback',
                method: 'post',
            },
            expect.anything(),
        );
    });
});
