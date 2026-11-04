import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { nextTick, reactive } from 'vue';
import DraftRunDialog from '@/components/admin/DraftRunDialog.vue';

const { submit, sent } = vi.hoisted(() => ({
    submit: vi.fn(),
    sent: { value: null as Record<string, unknown> | null },
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, string>) => {
        const form = reactive({
            ...data,
            errors: {} as Record<string, string>,
            processing: false,
            clearErrors: () => {},
            reset: () => Object.assign(form, data),
            transform: (
                callback: (
                    values: Record<string, unknown>,
                ) => Record<string, unknown>,
            ) => ({
                submit: (
                    action: unknown,
                    options: { onSuccess: () => void },
                ) => {
                    sent.value = callback({
                        confirm_hostname: (
                            form as unknown as { confirm_hostname: string }
                        ).confirm_hostname,
                    });
                    submit(action);
                    options.onSuccess();
                },
            }),
        });

        return form;
    },
}));

const submitButton = () =>
    Array.from(document.body.querySelectorAll('button')).find(
        (button) => button.getAttribute('type') === 'submit',
    );

describe('DraftRunDialog', () => {
    afterEach(() => {
        submit.mockClear();
        document.body.innerHTML = '';
    });

    it('runs the draft only once the hostname is typed again', async () => {
        const wrapper = mount(DraftRunDialog, {
            props: {
                open: true,
                'onUpdate:open': (value: boolean) =>
                    wrapper.setProps({ open: value }),
                action: {
                    url: '/admin/os-templates/t/scripts/x/run',
                    method: 'post' as const,
                },
                hostname: 'web1.example.com',
                data: { server: 'abc', draft: true, trace: false },
            },
            global: { mocks: { $t: (key: string) => key } },
            attachTo: document.body,
        });
        await nextTick();

        expect(
            document.body.querySelector('[data-test="confirm-hostname-target"]')
                ?.textContent,
        ).toContain('web1.example.com');
        expect(submitButton()?.hasAttribute('disabled')).toBe(true);

        const input = document.body.querySelector<HTMLInputElement>(
            'input[id^="draft-run-hostname-"]',
        )!;
        input.value = 'WEB1.example.com ';
        input.dispatchEvent(new Event('input'));
        await nextTick();
        expect(submitButton()?.hasAttribute('disabled')).toBe(false);

        submitButton()!.closest('form')!.dispatchEvent(new Event('submit'));
        await nextTick();

        expect(submit).toHaveBeenCalledOnce();
        expect(sent.value).toEqual({
            server: 'abc',
            draft: true,
            trace: false,
            confirm_hostname: 'WEB1.example.com ',
        });
        expect(wrapper.emitted('started')).toHaveLength(1);
        expect(wrapper.props('open')).toBe(false);
        wrapper.unmount();
    });
});
