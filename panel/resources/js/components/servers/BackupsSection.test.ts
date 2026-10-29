import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import BackupsSection from '@/components/servers/BackupsSection.vue';
import type { ServerBackup, ServerBackups } from '@/types/admin';

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
                reset: () => Object.assign(form, data),
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

vi.mock('@/routes/admin/servers/backups', () => ({
    store: (server: string) => ({
        url: `/admin/servers/${server}/backups`,
        method: 'post',
    }),
    restore: (args: { server: string; backup: string }) => ({
        url: `/admin/servers/${args.server}/backups/${args.backup}/restore`,
        method: 'post',
    }),
    destroy: (args: { server: string; backup: string }) => ({
        url: `/admin/servers/${args.server}/backups/${args.backup}`,
        method: 'delete',
    }),
}));

const backup = (
    n: number,
    overrides: Partial<ServerBackup> = {},
): ServerBackup => ({
    id: `backup-${n}`,
    kind: 'manual',
    label: `Backup ${n}`,
    size_bytes: 1024 ** 3,
    os_template: 'Ubuntu 24.04',
    created_at: '2026-10-29T01:00:00+00:00',
    storage: 'backup-nfs',
    restorable: true,
    unrestorable_reason: null,
    ...overrides,
});

const mountSection = (
    items: ServerBackup[],
    overrides: Partial<ServerBackups> = {},
    props: { busy?: boolean } = {},
) =>
    mount(BackupsSection, {
        props: {
            server: 'server-uuid',
            hostname: 'vps1.example.com',
            backups: {
                items,
                slots: 2,
                unavailable: null,
                storage: 'backup-nfs',
                ...overrides,
            },
            busy: props.busy ?? false,
        },
        global: {
            mocks: { $t: (text: string) => text },
            stubs: {
                ConfirmDelete: true,
                FormDialog: {
                    props: ['title'],
                    emits: ['submit'],
                    template:
                        '<form :data-dialog="title" @submit.prevent="$emit(\'submit\')"><slot /></form>',
                },
            },
        },
    });

describe('BackupsSection', () => {
    beforeEach(() => {
        submit.mockClear();
        submitted.value = null;
    });

    it('lists the backups with size, system and storage', () => {
        const wrapper = mountSection([backup(1)]);

        const rows = wrapper.findAll('[data-test="backup"]');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('Backup 1');
        expect(rows[0].text()).toContain('1 GiB');
        expect(rows[0].text()).toContain('Ubuntu 24.04');
        expect(rows[0].text()).toContain('backup-nfs');
        expect(wrapper.find('[data-test="backup-count"]').text()).toBe('1 / 2');
    });

    it('offers no new backup when the slots are full or none can be taken', () => {
        expect(
            mountSection([backup(1), backup(2)])
                .find('[data-test="take-backup"]')
                .attributes('disabled'),
        ).toBeDefined();

        const unavailable = mountSection([], {
            unavailable: 'No storage is marked for backups.',
        });
        expect(
            unavailable
                .find('[data-test="take-backup"]')
                .attributes('disabled'),
        ).toBeDefined();
        expect(
            unavailable.find('[data-test="backups-unavailable"]').text(),
        ).toBe('No storage is marked for backups.');
    });

    it('does not restore a backup the server cannot reach', () => {
        const wrapper = mountSection([
            backup(1, {
                restorable: false,
                unrestorable_reason: 'This backup is on another cluster.',
            }),
        ]);

        expect(
            wrapper.find('[data-test="restore-backup"]').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.find('[data-test="backup-unrestorable"]').text()).toBe(
            'This backup is on another cluster.',
        );
    });

    it('restores a backup once the hostname is typed', async () => {
        const wrapper = mountSection([backup(1)]);

        await wrapper.find('[data-test="restore-backup"]').trigger('click');
        await wrapper
            .find('#backup_confirm_hostname')
            .setValue('vps1.example.com');
        await wrapper
            .find('form[data-dialog="Restore backup"]')
            .trigger('submit');

        expect(submitted.value?.confirm_hostname).toBe('vps1.example.com');
        expect(submit).toHaveBeenCalledWith(
            {
                url: '/admin/servers/server-uuid/backups/backup-1/restore',
                method: 'post',
            },
            expect.anything(),
        );
    });
});
