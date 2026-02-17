import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MessageComposer from './MessageComposer.vue';

function mountComposer(props = {}) {
    return mount(MessageComposer, {
        props: { disabled: false, ...props },
    });
}

describe('messageComposer', () => {
    it('renders a textarea', () => {
        const wrapper = mountComposer();
        expect(wrapper.find('textarea').exists()).toBe(true);
    });

    it('renders a send button', () => {
        const wrapper = mountComposer();
        expect(wrapper.find('button[type="submit"]').exists()).toBe(true);
    });

    it('emits send event with message content on submit', async () => {
        const wrapper = mountComposer();
        await wrapper.find('textarea').setValue('Hello AI');
        await wrapper.find('form').trigger('submit');
        expect(wrapper.emitted('send')).toHaveLength(1);
        expect(wrapper.emitted('send')[0]).toEqual(['Hello AI']);
    });

    it('clears textarea after sending', async () => {
        const wrapper = mountComposer();
        await wrapper.find('textarea').setValue('Hello AI');
        await wrapper.find('form').trigger('submit');
        expect(wrapper.find('textarea').element.value).toBe('');
    });

    it('does not emit send for empty/whitespace input', async () => {
        const wrapper = mountComposer();
        await wrapper.find('textarea').setValue('   ');
        await wrapper.find('form').trigger('submit');
        expect(wrapper.emitted('send')).toBeUndefined();
    });

    it('disables textarea and button when disabled prop is true', () => {
        const wrapper = mountComposer({ disabled: true });
        expect(wrapper.find('textarea').attributes('disabled')).toBeDefined();
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
    });

    it('emits send on Enter without Shift', async () => {
        const wrapper = mountComposer();
        await wrapper.find('textarea').setValue('Hello');
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter', shiftKey: false });
        expect(wrapper.emitted('send')).toHaveLength(1);
    });

    it('does not emit send on Shift+Enter (allows newline)', async () => {
        const wrapper = mountComposer();
        await wrapper.find('textarea').setValue('Hello');
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter', shiftKey: true });
        expect(wrapper.emitted('send')).toBeUndefined();
    });
});
