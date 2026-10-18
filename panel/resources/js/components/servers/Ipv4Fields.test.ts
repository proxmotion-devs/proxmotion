import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import Ipv4Fields from '@/components/servers/Ipv4Fields.vue';
import type { Ipv4PoolOption } from '@/types/admin';

const pool = (
    value: string,
    overrides: Partial<Ipv4PoolOption> = {},
): Ipv4PoolOption => ({
    value,
    label: value,
    location: 'ist',
    network_model: 'bridged',
    free: 3,
    addresses: ['203.0.113.2', '203.0.113.3', '203.0.113.4'],
    ...overrides,
});

const pools = [
    pool('main'),
    pool('tiny', { free: 1, addresses: ['198.51.100.2'] }),
    pool('routed', { network_model: 'routed' }),
    pool('ams', { location: 'ams' }),
];

const mountFields = (count: number, chosenPool = '') => {
    // Deferred: the component already emits while it is mounted.
    const later = (props: Record<string, unknown>) =>
        void Promise.resolve().then(() => wrapper.setProps(props));

    const wrapper = mount(Ipv4Fields, {
        props: {
            pools,
            location: 'ist',
            networkModel: 'bridged',
            count,
            errors: {},
            pool: chosenPool,
            addresses: [],
            'onUpdate:pool': (value: string) => later({ pool: value }),
            'onUpdate:addresses': (value: string[]) =>
                later({ addresses: value }),
        },
        global: { mocks: { $t: (key: string) => key } },
    });

    return wrapper;
};

const poolOptions = (wrapper: ReturnType<typeof mountFields>) =>
    wrapper
        .findAll('#ipv4_pool option')
        .map((option) => option.element as HTMLOptionElement)
        .map((option) => ({ value: option.value, disabled: option.disabled }));

const tick = async (
    wrapper: ReturnType<typeof mountFields>,
    address: string,
) => {
    const box = wrapper
        .findAll('label')
        .find((label) => label.text().startsWith(address))
        ?.find('button');

    await box?.trigger('click');
    await flushPromises();
};

describe('Ipv4Fields', () => {
    it('offers the pools of the location and network model, without those too small', () => {
        expect(poolOptions(mountFields(2))).toEqual([
            { value: '', disabled: false },
            { value: 'main', disabled: false },
            { value: 'tiny', disabled: true },
        ]);
    });

    it('lists addresses only once a pool is chosen', () => {
        expect(
            mountFields(1).find('[data-test="ipv4-addresses"]').exists(),
        ).toBe(false);
        expect(
            mountFields(1, 'main')
                .find('[data-test="ipv4-addresses"]')
                .exists(),
        ).toBe(true);
    });

    it('lets as many addresses be ticked as the server gets, the first primary', async () => {
        const wrapper = mountFields(2, 'main');
        await flushPromises();

        await tick(wrapper, '203.0.113.4');
        await tick(wrapper, '203.0.113.2');
        expect(wrapper.props('addresses')).toEqual([
            '203.0.113.4',
            '203.0.113.2',
        ]);
        expect(
            wrapper
                .findAll('label')
                .find((label) => label.text().startsWith('203.0.113.4'))
                ?.text(),
        ).toContain('primary');

        const third = wrapper
            .findAll('label')
            .find((label) => label.text().startsWith('203.0.113.3'))
            ?.find('button');
        expect(third?.attributes('disabled')).toBeDefined();
    });

    it('drops chosen addresses when the pool changes and hides itself without IPv4', async () => {
        const wrapper = mountFields(2, 'main');
        await flushPromises();
        await tick(wrapper, '203.0.113.3');

        await wrapper.setProps({ pool: '' });
        await flushPromises();
        expect(wrapper.props('addresses')).toEqual([]);

        await wrapper.setProps({ count: 0 });
        expect(wrapper.find('#ipv4_pool').exists()).toBe(false);
    });
});
