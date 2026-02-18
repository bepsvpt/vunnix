import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BaseCard from '../BaseCard.vue';

describe('baseCard', () => {
    it('renders with default props and has base classes', () => {
        const wrapper = mount(BaseCard);
        const div = wrapper.find('div');
        expect(div.classes()).toContain('rounded-[var(--radius-card)]');
        expect(div.classes()).toContain('border');
        expect(div.classes()).toContain('bg-white');
        expect(div.classes()).toContain('shadow-[var(--shadow-card)]');
    });

    it('renders slot content', () => {
        const wrapper = mount(BaseCard, {
            slots: { default: '<p>Card content</p>' },
        });
        expect(wrapper.text()).toContain('Card content');
        expect(wrapper.find('p').exists()).toBe(true);
    });

    it('adds padding class when padded is true (default)', () => {
        const wrapper = mount(BaseCard);
        expect(wrapper.find('div').classes()).toContain('p-[var(--spacing-card)]');
    });

    it('does not add padding class when padded is false', () => {
        const wrapper = mount(BaseCard, {
            props: { padded: false },
        });
        expect(wrapper.find('div').classes()).not.toContain('p-[var(--spacing-card)]');
    });

    it('adds transition-shadow and hover class when hoverable is true', () => {
        const wrapper = mount(BaseCard, {
            props: { hoverable: true },
        });
        const classes = wrapper.find('div').classes();
        expect(classes).toContain('transition-shadow');
        expect(classes).toContain('hover:shadow-[var(--shadow-card-hover)]');
    });

    it('does not add hover classes when hoverable is false (default)', () => {
        const wrapper = mount(BaseCard);
        const classes = wrapper.find('div').classes();
        expect(classes).not.toContain('transition-shadow');
        expect(classes).not.toContain('hover:shadow-[var(--shadow-card-hover)]');
    });

    it('applies default variant border classes by default', () => {
        const wrapper = mount(BaseCard);
        const classes = wrapper.find('div').classes();
        expect(classes).toContain('border-zinc-200');
        expect(classes).toContain('dark:border-zinc-700');
    });

    it('applies emerald border classes for success variant', () => {
        const wrapper = mount(BaseCard, {
            props: { variant: 'success' },
        });
        const classes = wrapper.find('div').classes();
        expect(classes).toContain('border-emerald-200');
        expect(classes).toContain('dark:border-emerald-800');
    });

    it('applies red border classes for danger variant', () => {
        const wrapper = mount(BaseCard, {
            props: { variant: 'danger' },
        });
        const classes = wrapper.find('div').classes();
        expect(classes).toContain('border-red-200');
        expect(classes).toContain('dark:border-red-800');
    });
});
