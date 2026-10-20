import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import AddressesDialog from '@/components/servers/AddressesDialog.vue';
import type { AddressPoolOption, ServerAddress } from '@/types/admin';

const { submit } = vi.hoisted(() => ({ submit: vi.fn() }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        useForm: (data: Record<string, unknown>) =>
            reactive({
                ...data,
                errors: {},
                processing: false,
                clearErrors: () => undefined,
                submit,
            }),
    };
});

vi.mock('laravel-vue-i18n', () => ({ trans: (key: string) => key }));

const address = (
    value: string,
    overrides: Partial<ServerAddress> = {},
): ServerAddress => ({
    address: value,
    version: 4,
    primary: false,
    pool: { uuid: 'main', name: 'Main' },
    ptr: null,
    ptr_pending: false,
    ...overrides,
});

const pools: AddressPoolOption[] = [
    {
        value: 'main',
        label: 'Main (203.0.113.0/29)',
        version: 4,
        free: 2,
        addresses: ['203.0.113.5', '203.0.113.6'],
    },
];

const mountDialog = () =>
    mount(AddressesDialog, {
        props: {
            action: { url: '/admin/servers/x/addresses', method: 'post' },
            addresses: [
                address('203.0.113.2', { primary: true }),
                address('203.0.113.3'),
            ],
            pools,
        },
        global: {
            mocks: {
                $t: (key: string, replace?: Record<string, string>) =>
                    replace?.address ? `${key} ${replace.address}` : key,
            },
            stubs: {
                FormDialog: {
                    // Declared, so the parent's @submit is not also bound to the <form>.
                    emits: ['submit'],
                    template:
                        '<form @submit.prevent="$emit(\'submit\')"><slot /></form>',
                },
            },
        },
    });

const open = async (wrapper: ReturnType<typeof mountDialog>) => {
    await wrapper.find('button').trigger('click');
    await flushPromises();
};

describe('AddressesDialog', () => {
    beforeEach(() => submit.mockReset());

    it('asks for a new primary before the primary is removed', async () => {
        const wrapper = mountDialog();
        await open(wrapper);

        await wrapper.find('[data-test="remove-203.0.113.2"]').setValue(true);
        expect(wrapper.text()).toContain(
            'Choose a new primary address before removing :address. 203.0.113.2',
        );
        expect(wrapper.find('[data-test="release-warning"]').exists()).toBe(
            true,
        );

        await wrapper.find('#primary_ipv4').setValue('203.0.113.3');
        expect(wrapper.text()).not.toContain('Choose a new primary address');

        await wrapper.find('form').trigger('submit');

        expect(submit).toHaveBeenCalledOnce();
        const form = submit.mock.contexts.at(-1) as Record<string, unknown>;
        expect(form.remove).toEqual(['203.0.113.2']);
        expect(form.primary_ipv4).toBe('203.0.113.3');
        expect(form.primary_ipv6).toBeNull();
        expect(form.add).toEqual([]);
    });

    it('adds the next free or a specific address, which may become the primary', async () => {
        const wrapper = mountDialog();
        await open(wrapper);

        await wrapper.find('[data-test="add-row"]').trigger('click');
        await wrapper.find('[data-test="add-row"]').trigger('click');
        const second = wrapper.find('[data-test="add-row-1"]');
        await second.findAll('select')[0].setValue('main');
        await second.findAll('select')[1].setValue('specific');
        await second.find('input').setValue(' 203.0.113.6 ');

        const primaries = wrapper
            .findAll('#primary_ipv4 option')
            .map((option) => option.element.getAttribute('value'));
        expect(primaries).toContain('203.0.113.6');
        expect(
            second
                .findAll('datalist option')
                .map((option) => option.attributes('value')),
        ).toEqual(['203.0.113.5', '203.0.113.6']);

        await wrapper.find('form').trigger('submit');

        const form = submit.mock.contexts.at(-1) as Record<string, unknown>;
        expect(form.add).toEqual([
            { ip_version: 4, pool: null, address: null },
            { ip_version: 4, pool: 'main', address: '203.0.113.6' },
        ]);
        expect(form.primary_ipv4).toBeNull();
    });

    it('sends nothing when nothing changed', async () => {
        const wrapper = mountDialog();
        await open(wrapper);

        await wrapper.find('form').trigger('submit');

        expect(submit).not.toHaveBeenCalled();
    });
});
