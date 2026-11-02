import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import BrandingImageField from '@/components/admin/BrandingImageField.vue';

const { post, visit } = vi.hoisted(() => ({ post: vi.fn(), visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { post, visit } }));

// A FileList as the input reports it.
const fileList = (file: File) => ({ 0: file, length: 1, item: () => file });

const mountField = (url: string | null) =>
    mount(BrandingImageField, {
        props: { image: 'logo', label: 'Logo', hint: 'Square.', url },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('BrandingImageField', () => {
    beforeEach(() => {
        post.mockReset();
        visit.mockReset();
    });

    it('uploads a chosen file at once and keeps the page form as it is', async () => {
        const wrapper = mountField(null);
        const input = wrapper.find('[data-test="branding-logo-file"]');
        const file = new File(['png'], 'logo.png', { type: 'image/png' });
        Object.defineProperty(input.element, 'files', {
            value: fileList(file),
        });

        await input.trigger('change');

        expect(post).toHaveBeenCalledOnce();
        expect(post.mock.calls[0][0]).toMatchObject({
            url: '/admin/settings/branding/images/logo',
            method: 'post',
        });
        expect(post.mock.calls[0][1]).toEqual({ file });
        expect(post.mock.calls[0][2]).toMatchObject({
            forceFormData: true,
            preserveState: true,
        });
        expect(
            wrapper.find('[data-test="branding-logo-remove"]').exists(),
        ).toBe(false);
    });

    it('removes the image without resetting the form', async () => {
        const wrapper = mountField('/branding/logo.png');

        await wrapper
            .find('[data-test="branding-logo-remove"]')
            .trigger('click');

        expect(visit.mock.calls[0][0]).toMatchObject({
            url: '/admin/settings/branding/images/logo',
            method: 'delete',
        });
        expect(visit.mock.calls[0][1]).toMatchObject({ preserveState: true });
    });

    it('shows why an upload was refused', async () => {
        const wrapper = mountField(null);
        const input = wrapper.find('[data-test="branding-logo-file"]');
        Object.defineProperty(input.element, 'files', {
            value: fileList(new File(['x'], 'logo.svg')),
        });

        await input.trigger('change');
        post.mock.calls[0][2].onError({ file: 'The file must be an image.' });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[role="alert"]').text()).toBe(
            'The file must be an image.',
        );
    });
});
