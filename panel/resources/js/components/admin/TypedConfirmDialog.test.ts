import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { nextTick, reactive } from 'vue';
import TypedConfirmDialog from '@/components/admin/TypedConfirmDialog.vue';

const { submit } = vi.hoisted(() => ({ submit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, string>) => {
        const form = reactive({
            ...data,
            errors: {} as Record<string, string>,
            processing: false,
            clearErrors: () => {},
            reset: () => Object.assign(form, data),
            submit,
        });

        return form;
    },
}));

const mountDialog = () =>
    mount(TypedConfirmDialog, {
        props: {
            action: { url: '/admin/servers/abc', method: 'delete' },
            hostname: 'web1.example.com',
            title: 'Delete this server?',
            description: 'This cannot be undone.',
            label: 'Delete server',
            submitLabel: 'Delete server',
            destructive: true,
        },
        attrs: { 'data-test': 'server-delete' },
        global: { mocks: { $t: (key: string) => key } },
        attachTo: document.body,
    });

const submitButton = () =>
    Array.from(document.body.querySelectorAll('button')).find(
        (button) =>
            button.getAttribute('type') === 'submit' &&
            button.textContent?.includes('Delete server'),
    );

describe('TypedConfirmDialog', () => {
    afterEach(() => {
        submit.mockClear();
        document.body.innerHTML = '';
    });

    it('passes attributes to its trigger button', () => {
        const wrapper = mountDialog();

        expect(wrapper.find('[data-test="server-delete"]').text()).toContain(
            'Delete server',
        );
        wrapper.unmount();
    });

    it('keeps the confirm button disabled until the hostname matches', async () => {
        const wrapper = mountDialog();
        await wrapper.find('[data-test="server-delete"]').trigger('click');
        await nextTick();

        const input = document.body.querySelector<HTMLInputElement>(
            'input[placeholder="web1.example.com"]',
        );
        expect(input).not.toBeNull();
        expect(submitButton()?.hasAttribute('disabled')).toBe(true);

        input!.value = 'web2.example.com';
        input!.dispatchEvent(new Event('input'));
        await nextTick();
        expect(submitButton()?.hasAttribute('disabled')).toBe(true);

        input!.value = ' WEB1.example.com ';
        input!.dispatchEvent(new Event('input'));
        await nextTick();
        expect(submitButton()?.hasAttribute('disabled')).toBe(false);

        submitButton()!.closest('form')!.dispatchEvent(new Event('submit'));
        await nextTick();
        expect(submit).toHaveBeenCalledOnce();
        wrapper.unmount();
    });
});
