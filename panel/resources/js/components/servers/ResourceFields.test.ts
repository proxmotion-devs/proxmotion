import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import ResourceFields from '@/components/servers/ResourceFields.vue';
import type { NewServerResources, PackageOption } from '@/types/admin';

const pkg: PackageOption = {
    value: 'small',
    label: 'Small',
    network_models: ['bridged'],
    placement_groups: [],
    cpu_cores: 2,
    memory_mib: 4096,
    disk_gib: 40,
    ipv4_count: 1,
    ipv6_count: 1,
    port_speed_mbps: 1000,
    traffic_gb: null,
};

const empty: NewServerResources = {
    cpu_cores: 1,
    memory_mib: 1024,
    disk_gib: 20,
    ipv4_count: 1,
    ipv6_count: 0,
    port_speed_mbps: '',
    traffic_gb: '',
};

const mountFields = (packageUuid = '', values = empty) => {
    // Deferred: the component emits while the test changes it.
    const later = (props: Record<string, unknown>) =>
        void Promise.resolve().then(() => wrapper.setProps(props));

    const wrapper = mount(ResourceFields, {
        props: {
            packages: [pkg],
            errors: {},
            package: packageUuid,
            values,
            'onUpdate:package': (value: string) => later({ package: value }),
            'onUpdate:values': (value: NewServerResources) =>
                later({ values: value }),
        },
        global: { mocks: { $t: (key: string) => key } },
    });

    return wrapper;
};

describe('ResourceFields', () => {
    it('fills every field from the chosen package', async () => {
        const wrapper = mountFields();

        await wrapper.find('#package').setValue('small');
        await flushPromises();

        expect(wrapper.props('package')).toBe('small');
        expect(wrapper.props('values')).toEqual({
            cpu_cores: 2,
            memory_mib: 4096,
            disk_gib: 40,
            ipv4_count: 1,
            ipv6_count: 1,
            port_speed_mbps: 1000,
            traffic_gb: '',
        });
    });

    it('keeps the values editable and shows the package value of a changed field', async () => {
        const wrapper = mountFields('small', {
            ...empty,
            cpu_cores: 2,
            memory_mib: 8192,
            disk_gib: 40,
            ipv6_count: 1,
            port_speed_mbps: 1000,
        });

        expect(wrapper.text()).toContain('Package: 4096 MiB');
        expect(wrapper.text()).not.toContain('Package: 2');

        await wrapper.find('#cpu_cores').setValue('4');
        await flushPromises();
        expect(wrapper.props('values').cpu_cores).toBe(4);
        expect(wrapper.props('package')).toBe('small');
    });

    it('leaves the values alone when no package is chosen', async () => {
        const wrapper = mountFields('small', empty);

        await wrapper.find('#package').setValue('');
        await flushPromises();

        expect(wrapper.props('package')).toBe('');
        expect(wrapper.props('values')).toEqual(empty);
    });
});
