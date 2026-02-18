import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BaseButton from '../BaseButton.vue';

function mountButton(props: Record<string, unknown> = {}, slots: Record<string, string> = {}) {
    return mount(BaseButton, {
        props,
        slots: { default: 'Click me', ...slots },
    });
}

describe('baseButton', () => {
    it('renders with default props (primary, md, button type)', () => {
        const wrapper = mountButton();
        const button = wrapper.find('button');
        expect(button.exists()).toBe(true);
        expect(button.attributes('type')).toBe('button');
        expect(button.classes()).toContain('bg-zinc-900');
        expect(button.classes()).toContain('px-4');
        expect(button.classes()).toContain('text-sm');
    });

    it('renders slot content', () => {
        const wrapper = mountButton({}, { default: 'Save changes' });
        expect(wrapper.text()).toContain('Save changes');
    });

    it('disabled prop disables the button', () => {
        const wrapper = mountButton({ disabled: true });
        const button = wrapper.find('button');
        expect(button.attributes('disabled')).toBeDefined();
    });

    it('loading state shows spinner SVG and disables button', () => {
        const wrapper = mountButton({ loading: true });
        const button = wrapper.find('button');
        expect(button.attributes('disabled')).toBeDefined();
        const svg = wrapper.find('svg');
        expect(svg.exists()).toBe(true);
        expect(svg.classes()).toContain('animate-spin');
    });

    it('does not show spinner when not loading', () => {
        const wrapper = mountButton({ loading: false });
        expect(wrapper.find('svg').exists()).toBe(false);
    });

    it.each([
        ['primary', 'bg-zinc-900'],
        ['secondary', 'border'],
        ['ghost', 'text-zinc-600'],
        ['danger', 'bg-red-600'],
    ] as const)('variant "%s" applies correct classes', (variant, expectedClass) => {
        const wrapper = mountButton({ variant });
        expect(wrapper.find('button').classes()).toContain(expectedClass);
    });

    it.each([
        ['sm', 'px-3', 'text-xs'],
        ['md', 'px-4', 'text-sm'],
        ['lg', 'px-5', 'text-sm'],
    ] as const)('size "%s" applies correct classes', (size, expectedPadding, expectedText) => {
        const wrapper = mountButton({ size });
        const classes = wrapper.find('button').classes();
        expect(classes).toContain(expectedPadding);
        expect(classes).toContain(expectedText);
    });

    it('type="submit" is applied to button element', () => {
        const wrapper = mountButton({ type: 'submit' });
        expect(wrapper.find('button').attributes('type')).toBe('submit');
    });

    it('emits click event when clicked', async () => {
        const wrapper = mountButton();
        await wrapper.find('button').trigger('click');
        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    it('does not emit click event when disabled', async () => {
        const wrapper = mountButton({ disabled: true });
        await wrapper.find('button').trigger('click');
        expect(wrapper.emitted('click')).toBeUndefined();
    });

    it('does not emit click event when loading', async () => {
        const wrapper = mountButton({ loading: true });
        await wrapper.find('button').trigger('click');
        expect(wrapper.emitted('click')).toBeUndefined();
    });
});
