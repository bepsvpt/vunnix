import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import MessageComposer from '../MessageComposer.vue';

function mountComposer(props: Record<string, unknown> = {}) {
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

    it('does not emit send on Enter during IME composition (isComposing)', async () => {
        const wrapper = mountComposer();
        await wrapper.find('textarea').setValue('你');
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter', isComposing: true });
        expect(wrapper.emitted('send')).toBeUndefined();
    });

    it('does not emit send on Enter after compositionend (Chrome event order)', async () => {
        vi.useFakeTimers();
        const wrapper = mountComposer();
        const textarea = wrapper.find('textarea');
        await textarea.setValue('你好');
        // Chrome fires: compositionstart → compositionend → keydown (isComposing: false)
        await textarea.trigger('compositionstart');
        await textarea.trigger('compositionend');
        await textarea.trigger('keydown', { key: 'Enter', isComposing: false });
        expect(wrapper.emitted('send')).toBeUndefined();
        vi.useRealTimers();
    });

    it('allows send on Enter after composition fully completes', async () => {
        vi.useFakeTimers();
        const wrapper = mountComposer();
        const textarea = wrapper.find('textarea');
        await textarea.setValue('你好');
        await textarea.trigger('compositionstart');
        await textarea.trigger('compositionend');
        // Advance past the requestAnimationFrame that clears the composing flag
        vi.runAllTimers();
        await textarea.trigger('keydown', { key: 'Enter', isComposing: false });
        expect(wrapper.emitted('send')).toHaveLength(1);
        vi.useRealTimers();
    });
});
