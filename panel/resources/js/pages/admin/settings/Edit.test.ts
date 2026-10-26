import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import Edit from '@/pages/admin/settings/Edit.vue';
import type { PanelSettings } from '@/types/admin';

const { submit, transformed } = vi.hoisted(() => ({
    submit: vi.fn(),
    transformed: { value: null as Record<string, unknown> | null },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        Head: { template: '<div />' },
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
    ...overrides,
});

const mountPage = (unsaved: string[] = []) =>
    mount(Edit, {
        props: {
            settings: settings(),
            unsaved,
            environment: {
                require_admin_two_factor: true,
                console_proxy: false,
            },
        },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('Panel settings page', () => {
    beforeEach(() => {
        submit.mockReset();
        transformed.value = null;
    });

    it('sends every setting, an empty bandwidth limit as null', async () => {
        const wrapper = mountPage();

        expect(
            (wrapper.find('#protected_networks').element as HTMLTextAreaElement)
                .value,
        ).toBe('198.51.100.0/24\n203.0.113.7');

        await wrapper.find('form').trigger('submit');

        expect(submit).toHaveBeenCalledOnce();
        expect(transformed.value).toMatchObject({
            cross_cluster_moves: false,
            move_bwlimit_kib: null,
            verify_dns_name: 'example.com',
            docs_url: '',
            traffic_reset_day: 1,
            traffic_action: 'none',
            usage_cpu_percent: null,
            usage_network_mbps: null,
            usage_window_minutes: 60,
            usage_action: 'none',
        });
    });

    it('says when values still come from .env', () => {
        expect(
            mountPage(['min_vmid'])
                .find('[data-test="settings-unsaved"]')
                .exists(),
        ).toBe(true);
        expect(
            mountPage().find('[data-test="settings-unsaved"]').exists(),
        ).toBe(false);
    });
});
