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
        useForm: (initial: Record<string, unknown>) => {
            let data = { ...initial };
            let transformer = (values: Record<string, unknown>) => values;
            const form: Record<string, unknown> = reactive({
                ...data,
                errors: {},
                processing: false,
                defaults: (values: Record<string, unknown>) => {
                    data = { ...data, ...values };
                },
                reset: () => Object.assign(form, data),
                clearErrors: () => {
                    form.errors = {};
                },
                transform: (
                    callback: (
                        values: Record<string, unknown>,
                    ) => Record<string, unknown>,
                ) => {
                    transformer = callback;

                    return form;
                },
                submit: (
                    route: unknown,
                    options: { onSuccess?: () => void } = {},
                ) => {
                    submitted.value = transformer({ ...form });
                    submit(route, options);
                    options.onSuccess?.();
                },
            });

            return form;
        },
    };
});

vi.mock('laravel-vue-i18n', () => ({
    trans: (key: string, replace: Record<string, string> = {}) =>
        Object.entries(replace).reduce(
            (text, [name, value]) => text.replace(`:${name}`, value),
            key,
        ),
}));

vi.mock('@/routes/admin/servers', () => ({
    backupSchedule: (server: string) => ({
        url: `/admin/servers/${server}/backup-schedule`,
        method: 'put',
    }),
}));

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

const schedule = (
    overrides: Partial<ServerBackups['schedule']> = {},
): ServerBackups['schedule'] => ({
    own: null,
    package: 'none',
    effective: 'none',
    next_at: null,
    blocked: null,
    ...overrides,
});

const mountSection = (
    items: ServerBackup[],
    overrides: Partial<ServerBackups> = {},
    props: { busy?: boolean; terminated?: boolean } = {},
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
                schedule: schedule(),
                ...overrides,
            },
            busy: props.busy ?? false,
            terminated: props.terminated ?? false,
        },
        global: {
            mocks: { $t: (text: string) => text },
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

    it('keeps the destructive restore locked until the hostname matches', async () => {
        const wrapper = mountSection([backup(1)]);

        await wrapper.find('[data-test="restore-backup"]').trigger('click');
        const dialog = () => wrapper.find('form[data-dialog="Restore backup"]');

        expect(dialog().attributes('data-destructive')).toBe('true');
        expect(dialog().attributes('data-submit-disabled')).toBe('true');
        // The hostname is shown as text to copy, not as a hint or placeholder.
        expect(
            dialog().find('[data-test="confirm-hostname-target"]').text(),
        ).toContain('vps1.example.com');

        await wrapper
            .find('#backup_confirm_hostname')
            .setValue('  VPS1.example.com ');
        expect(dialog().attributes('data-submit-disabled')).toBe('false');
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

    it('shows the schedule that applies and when the next backup is due', () => {
        const wrapper = mountSection(
            [backup(1, { kind: 'scheduled', label: null })],
            {
                schedule: schedule({
                    package: 'daily',
                    effective: 'daily',
                    next_at: '2026-11-03T01:10:00+00:00',
                }),
            },
        );

        const row = wrapper.find('[data-test="backup-schedule"]');
        expect(row.text()).toContain('Every day');
        expect(row.text()).toContain("package's schedule");
        expect(wrapper.find('[data-test="backup-schedule-next"]').text()).toBe(
            'Next scheduled backup: :time',
        );
        expect(wrapper.find('[data-test="backup"]').text()).toContain(
            'Scheduled backup',
        );
        expect(wrapper.find('[data-test="backup-scheduled"]').exists()).toBe(
            true,
        );
    });

    it('says why no scheduled backup is taken', () => {
        const wrapper = mountSection([backup(1), backup(2)], {
            schedule: schedule({
                own: 'weekly',
                effective: 'weekly',
                blocked: 'Manual backups fill every backup slot.',
            }),
        });

        expect(
            wrapper.find('[data-test="backup-schedule-source"]').text(),
        ).toContain("server's own schedule");
        expect(
            wrapper.find('[data-test="backup-schedule-blocked"]').text(),
        ).toBe('Manual backups fill every backup slot.');
        expect(
            wrapper.find('[data-test="backup-schedule-next"]').exists(),
        ).toBe(false);
    });

    it("saves the server's own schedule, or none for its package's", async () => {
        const wrapper = mountSection([], {
            schedule: schedule({ own: 'weekly', effective: 'weekly' }),
        });

        await wrapper
            .find('[data-test="change-backup-schedule"]')
            .trigger('click');
        expect(
            (wrapper.find('#backup_schedule').element as HTMLSelectElement)
                .value,
        ).toBe('weekly');

        await wrapper.find('#backup_schedule').setValue('');
        await wrapper
            .find('form[data-dialog="Backup schedule"]')
            .trigger('submit');

        expect(submitted.value).toEqual({ schedule: null });
        expect(submit).toHaveBeenCalledWith(
            {
                url: '/admin/servers/server-uuid/backup-schedule',
                method: 'put',
            },
            expect.anything(),
        );
    });

    it('only offers deleting the kept backups of a terminated server', () => {
        const wrapper = mountSection(
            [backup(1)],
            { schedule: schedule({ package: 'daily', effective: 'daily' }) },
            { terminated: true },
        );

        expect(wrapper.find('[data-test="take-backup"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="restore-backup"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-test="backup-schedule"]').exists()).toBe(
            false,
        );
        expect(wrapper.findAll('[data-test="backup"]')).toHaveLength(1);
    });
});
