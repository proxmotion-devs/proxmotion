import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vite-plus/test';
import { nextTick } from 'vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';

describe('ConfirmDialog', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('asks in the panel and emits confirm before it closes', async () => {
        const events: string[] = [];
        const wrapper = mount(ConfirmDialog, {
            props: {
                open: true,
                title: 'Replace the draft?',
                description: 'Replace the draft with the recipe in app.zip?',
                confirmLabel: 'Replace draft',
                destructive: true,
                'onUpdate:open': (open: boolean) => events.push(`open:${open}`),
                onConfirm: () => events.push('confirm'),
            },
            global: { mocks: { $t: (key: string) => key } },
            attachTo: document.body,
        });
        await nextTick();

        expect(document.body.textContent).toContain('Replace the draft?');
        expect(document.body.textContent).toContain(
            'Replace the draft with the recipe in app.zip?',
        );

        document.body
            .querySelector<HTMLButtonElement>(
                '[data-test="confirm-dialog-confirm"]',
            )!
            .click();
        await nextTick();

        // A caller keeping its pending value in the open state still has it on confirm.
        expect(events).toEqual(['confirm', 'open:false']);
        wrapper.unmount();
    });
});
