import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import ResourceFields from '@/components/servers/ResourceFields.vue';
import type { NewServerResources } from '@/types/admin';

const values: NewServerResources = {
    cpu_cores: 1,
    memory_mib: 1024,
    disk_gib: 20,
    ipv4_count: 1,
    ipv6_count: 0,
    port_speed_mbps: '',
    traffic_gb: '',
    cpu_limit: '',
    cpu_units: '',
    disk_mb_s: '',
    disk_iops: '',
};

const mountFields = () => {
    // Deferred: the component emits while the test changes it.
    const later = (props: Record<string, unknown>) =>
        void Promise.resolve().then(() => wrapper.setProps(props));

    const wrapper = mount(ResourceFields, {
        props: {
            errors: { memory_mib: 'Too little memory.' },
            values,
            'onUpdate:values': (value: NewServerResources) =>
                later({ values: value }),
        },
        global: { mocks: { $t: (key: string) => key } },
    });

    return wrapper;
};

describe('ResourceFields', () => {
    it('edits the values as numbers, empty limits staying unlimited', async () => {
        const wrapper = mountFields();

        await wrapper.find('#cpu_cores').setValue('4');
        await flushPromises();
        await wrapper.find('#port_speed_mbps').setValue('500');
        await flushPromises();
        await wrapper.find('#port_speed_mbps').setValue('');
        await flushPromises();

        expect(wrapper.props('values')).toEqual({
            ...values,
            cpu_cores: 4,
            port_speed_mbps: '',
        });
    });

    it('edits the CPU and disk limits', async () => {
        const wrapper = mountFields();

        await wrapper.find('#cpu_limit').setValue('1.5');
        await flushPromises();
        await wrapper.find('#disk_iops').setValue('500');
        await flushPromises();

        expect(wrapper.props('values')).toEqual({
            ...values,
            cpu_limit: 1.5,
            disk_iops: 500,
        });
    });

    it('shows the errors of its fields', () => {
        expect(mountFields().text()).toContain('Too little memory.');
    });
});
