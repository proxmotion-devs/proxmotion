import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import type { SettingsCategoryKey } from '@/lib/settingsCategories';
import Edit from '@/pages/admin/settings/Edit.vue';
import type { PanelSettings } from '@/types/admin';

const { submit, transformed, layoutProps, visit } = vi.hoisted(() => ({
    submit: vi.fn(),
    visit: vi.fn(),
    transformed: { value: null as Record<string, unknown> | null },
    layoutProps: vi.fn(),
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        Head: { template: '<div />' },
        setLayoutProps: layoutProps,
        router: { visit },
        useForm: (data: Record<string, unknown>) => {
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                transform: (
                    callback: (
                        values: Record<string, unknown>,
                    ) => Record<string, unknown>,
                ) => ({
                    submit: (...args: unknown[]) => {
                        transformed.value = callback({ ...data, ...form });
                        submit(...args);
                    },
                }),
            });

            return form;
        },
    };
});

const settings = (overrides: Partial<PanelSettings> = {}): PanelSettings => ({
    cross_cluster_moves: false,
    move_deadline_minutes: 720,
    move_bwlimit_kib: null,
    min_vmid: 0,
    provisioning_deadline_minutes: 120,
    verify_dns_name: 'example.com',
    protected_networks: ['198.51.100.0/24', '203.0.113.7'],
    drift_stopped_grace_minutes: 60,
    storage_warning_percent: 85,
    storage_critical_percent: 95,
    console_max_minutes: 60,
    console_idle_minutes: 15,
    docs_url: null,
    traffic_reset_day: 1,
    traffic_counted: 'both',
    traffic_action: 'none',
    usage_cpu_percent: null,
    usage_memory_percent: null,
    usage_disk_mb_s: null,
    usage_network_mbps: null,
    usage_window_minutes: 60,
    usage_action: 'none',
    password_length: 20,
    password_characters: 'mixed',
    password_prefix: null,
    password_suffix: null,
    ssh_keys_max: 20,
    notifications_enabled: false,
    notification_recipients: [],
    notification_throttle_minutes: 60,
    ...overrides,
});

const mountPage = (
    category: SettingsCategoryKey,
    fields: string[],
    unsaved: string[] = [],
    overrides: Partial<PanelSettings> = {},
) =>
    mount(Edit, {
        props: {
            category,
            fields,
            settings: settings(overrides),
            unsaved,
            environment: {
                require_admin_two_factor: true,
                console_proxy: false,
                mailer: 'log',
                mail_from: 'panel@example.com',
            },
        },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('Panel settings page', () => {
    beforeEach(() => {
        submit.mockReset();
        visit.mockReset();
        layoutProps.mockReset();
        transformed.value = null;
    });

    it('shows only its category and sends only its settings', async () => {
        const wrapper = mountPage('network', ['protected_networks']);

        expect(
            (wrapper.find('#protected_networks').element as HTMLTextAreaElement)
                .value,
        ).toBe('198.51.100.0/24\n203.0.113.7');
        expect(wrapper.find('#min_vmid').exists()).toBe(false);
        expect(wrapper.find('#usage_cpu_percent').exists()).toBe(false);

        await wrapper.find('form').trigger('submit');

        expect(wrapper.findAll('button[type="submit"]')).toHaveLength(1);
        expect(submit).toHaveBeenCalledOnce();
        expect(submit.mock.calls[0][0]).toMatchObject({
            url: '/admin/settings/network',
            method: 'put',
        });
        expect(transformed.value).toEqual({
            protected_networks: '198.51.100.0/24\n203.0.113.7',
        });
    });

    it('sends empty limits as null', async () => {
        const fields = [
            'traffic_reset_day',
            'traffic_counted',
            'traffic_action',
            'usage_cpu_percent',
            'usage_memory_percent',
            'usage_disk_mb_s',
            'usage_network_mbps',
            'usage_window_minutes',
            'usage_action',
        ];
        const wrapper = mountPage('usage', fields);

        await wrapper.find('form').trigger('submit');

        expect(Object.keys(transformed.value ?? {})).toEqual(fields);
        expect(transformed.value).toMatchObject({
            traffic_reset_day: 1,
            usage_cpu_percent: null,
            usage_network_mbps: null,
            usage_window_minutes: 60,
            usage_action: 'none',
        });
    });

    it('is titled after its category in the breadcrumbs', () => {
        mountPage('usage', ['traffic_reset_day']);

        expect(layoutProps).toHaveBeenCalledWith({
            breadcrumbs: [
                expect.objectContaining({ title: 'Panel settings' }),
                expect.objectContaining({ title: 'Usage and quotas' }),
            ],
        });
    });

    it('has nothing to save on the .env page', () => {
        const wrapper = mountPage('environment', []);

        expect(
            wrapper.find('[data-test="settings-environment"]').exists(),
        ).toBe(true);
        expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
    });

    it('warns when generated passwords do not fit cloud-init', async () => {
        const fields = ['password_prefix', 'password_suffix'];
        const hint = (wrapper: ReturnType<typeof mountPage>) =>
            wrapper.find('[data-test="password-cloud-init"]').exists();

        expect(hint(mountPage('servers', fields))).toBe(false);
        expect(
            hint(
                mountPage('servers', fields, [], {
                    password_suffix: '.example.com',
                }),
            ),
        ).toBe(false);

        const wrapper = mountPage('servers', fields, [], {
            password_prefix: '@',
        });
        expect(hint(wrapper)).toBe(true);

        await wrapper.find('#password_prefix').setValue('Acme-');
        expect(hint(wrapper)).toBe(false);

        await wrapper.find('#password_suffix').setValue(':x');
        expect(hint(wrapper)).toBe(true);
    });

    it('says when values still come from .env', () => {
        expect(
            mountPage('servers', ['min_vmid'], ['min_vmid'])
                .find('[data-test="settings-unsaved"]')
                .exists(),
        ).toBe(true);
        expect(
            mountPage('servers', ['min_vmid'])
                .find('[data-test="settings-unsaved"]')
                .exists(),
        ).toBe(false);
    });

    it('sends the notification settings and queues a test email', async () => {
        const wrapper = mountPage('notifications', [
            'notifications_enabled',
            'notification_recipients',
            'notification_throttle_minutes',
        ]);

        // The mailer of the fixture only logs.
        expect(
            wrapper.find('[data-test="mailer-not-delivering"]').exists(),
        ).toBe(true);

        await wrapper
            .find('#notification_recipients')
            .setValue('ops@example.com\nnoc@example.com');
        await wrapper.find('form').trigger('submit');

        expect(submit.mock.calls[0][0]).toMatchObject({
            url: '/admin/settings/notifications',
        });
        expect(transformed.value).toEqual({
            notifications_enabled: false,
            notification_recipients: 'ops@example.com\nnoc@example.com',
            notification_throttle_minutes: 60,
        });

        await wrapper.find('[data-test="send-test-email"]').trigger('click');

        expect(submit).toHaveBeenCalledOnce();
        expect(visit.mock.calls[0][0]).toMatchObject({
            url: '/admin/settings/notifications/test',
            method: 'post',
        });
    });
});
