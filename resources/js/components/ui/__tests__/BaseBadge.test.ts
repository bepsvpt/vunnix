import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BaseBadge from '../BaseBadge.vue';

describe('baseBadge', () => {
    it('renders with neutral variant by default', () => {
        const wrapper = mount(BaseBadge);
        expect(wrapper.classes()).toContain('bg-zinc-100');
        expect(wrapper.classes()).toContain('text-zinc-600');
    });

    it('renders slot content', () => {
        const wrapper = mount(BaseBadge, {
            slots: { default: 'Active' },
        });
        expect(wrapper.text()).toBe('Active');
    });

    it.each([
        { variant: 'neutral' as const, expected: ['bg-zinc-100', 'text-zinc-600'] },
        { variant: 'success' as const, expected: ['bg-emerald-100', 'text-emerald-700'] },
        { variant: 'warning' as const, expected: ['bg-amber-100', 'text-amber-700'] },
        { variant: 'danger' as const, expected: ['bg-red-100', 'text-red-700'] },
        { variant: 'info' as const, expected: ['bg-blue-100', 'text-blue-700'] },
    ])('applies correct classes for $variant variant', ({ variant, expected }) => {
        const wrapper = mount(BaseBadge, {
            props: { variant },
        });
        for (const cls of expected) {
            expect(wrapper.classes()).toContain(cls);
        }
    });

    it('contains base classes for pill shape and typography', () => {
        const wrapper = mount(BaseBadge);
        expect(wrapper.classes()).toContain('inline-flex');
        expect(wrapper.classes()).toContain('items-center');
        expect(wrapper.classes()).toContain('text-xs');
        expect(wrapper.classes()).toContain('font-medium');
        expect(wrapper.classes()).toContain('leading-tight');
        expect(wrapper.classes()).toContain('rounded-[var(--radius-badge)]');
        expect(wrapper.classes()).toContain('px-2');
        expect(wrapper.classes()).toContain('py-0.5');
    });
});
