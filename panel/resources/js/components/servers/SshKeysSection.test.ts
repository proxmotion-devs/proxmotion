import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import SshKeysSection from '@/components/servers/SshKeysSection.vue';
import type { ServerSshKeys, SshKey } from '@/types/admin';

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
                submit: (...args: unknown[]) => {
                    submitted.value = { ...form };
                    submit(...args);
                },
            });

            return form;
        },
    };
});

vi.mock('@/routes/admin/servers', () => ({
    sshKeys: (server: string) => ({
        url: `/admin/servers/${server}/ssh-keys`,
        method: 'put',
    }),
}));

const key = (n: number, overrides: Partial<SshKey> = {}): SshKey => ({
    type: 'ssh-ed25519',
    fingerprint: `SHA256:key${n}`,
    comment: `user${n}@example.com`,
    public_key: `ssh-ed25519 AAAA${n} user${n}@example.com`,
    ...overrides,
});

const mountSection = (
    keys: SshKey[],
    props: { changing?: boolean; max?: number } = {},
) =>
    mount(SshKeysSection, {
        props: {
            server: 'server-uuid',
            sshKeys: { keys, max: props.max ?? 20 } as ServerSshKeys,
            changing: props.changing ?? false,
        },
        global: {
            mocks: { $t: (text: string) => text },
            stubs: {
                FormDialog: {
                    props: ['title'],
                    emits: ['submit'],
                    template:
                        '<form :data-dialog="title" @submit.prevent="$emit(\'submit\')"><slot /></form>',
                },
            },
        },
    });

describe('SshKeysSection', () => {
    beforeEach(() => {
        submit.mockClear();
        submitted.value = null;
    });

    it('lists the keys in a table with readable types', () => {
        const wrapper = mountSection([
            key(1),
            key(2, {
                type: 'sk-ecdsa-sha2-nistp256@openssh.com',
                comment: '',
            }),
        ]);

        const rows = wrapper.findAll('[data-test="ssh-key"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('user1@example.com');
        expect(rows[0].text()).toContain('Ed25519');
        expect(rows[0].text()).toContain('SHA256:key1');
        expect(rows[1].text()).toContain('No comment');
        expect(rows[1].text()).toContain('ECDSA P-256');
        expect(rows[1].text()).toContain('Security key');
        expect(wrapper.find('[data-test="ssh-key-count"]').text()).toBe(
            '2 / 20',
        );
    });

    it('sends the whole list without the deleted key', async () => {
        const wrapper = mountSection([key(1), key(2), key(3)]);

        await wrapper
            .findAll('[data-test="delete-ssh-key"]')[1]
            .trigger('click');
        await wrapper
            .find('form[data-dialog="Delete SSH key"]')
            .trigger('submit');

        expect(submitted.value?.ssh_keys).toEqual([
            key(1).public_key,
            key(3).public_key,
        ]);
        expect(submit).toHaveBeenCalledWith(
            { url: '/admin/servers/server-uuid/ssh-keys', method: 'put' },
            expect.anything(),
        );
    });

    it('sends the whole list with the added keys', async () => {
        const wrapper = mountSection([key(1)]);

        await wrapper
            .find('textarea')
            .setValue('ssh-rsa AAAAnew new@example.com');
        await wrapper.find('form[data-dialog="Add SSH key"]').trigger('submit');

        expect(submitted.value?.ssh_keys).toEqual([
            key(1).public_key,
            'ssh-rsa AAAAnew new@example.com',
        ]);
    });

    it('filters long lists', async () => {
        const wrapper = mountSection([1, 2, 3, 4, 5, 6].map((n) => key(n)));

        await wrapper.find('input[type="search"]').setValue('user4');

        const rows = wrapper.findAll('[data-test="ssh-key"]');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('4');
        expect(
            mountSection([key(1)])
                .find('input[type="search"]')
                .exists(),
        ).toBe(false);
    });

    it('waits while a change runs and when the server is full', () => {
        const changing = mountSection([key(1)], { changing: true });

        expect(changing.find('[data-test="ssh-keys-changing"]').exists()).toBe(
            true,
        );
        expect(
            changing.find('[data-test="add-ssh-key"]').attributes('disabled'),
        ).toBeDefined();
        expect(
            changing
                .find('[data-test="delete-ssh-key"]')
                .attributes('disabled'),
        ).toBeDefined();

        const full = mountSection([key(1), key(2)], { max: 2 });
        expect(
            full.find('[data-test="add-ssh-key"]').attributes('disabled'),
        ).toBeDefined();
    });
});
