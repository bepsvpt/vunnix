import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BaseSpinner from '../BaseSpinner.vue';

describe('baseSpinner', () => {
    it('renders with default size (md) and has animate-spin class', () => {
        const wrapper = mount(BaseSpinner);
        const svg = wrapper.find('svg');
        expect(svg.exists()).toBe(true);
        expect(svg.classes()).toContain('animate-spin');
        expect(svg.classes()).toContain('h-5');
        expect(svg.classes()).toContain('w-5');
    });

    it('renders sm size', () => {
        const wrapper = mount(BaseSpinner, { props: { size: 'sm' } });
        const svg = wrapper.find('svg');
        expect(svg.classes()).toContain('h-4');
        expect(svg.classes()).toContain('w-4');
    });

    it('renders lg size', () => {
        const wrapper = mount(BaseSpinner, { props: { size: 'lg' } });
        const svg = wrapper.find('svg');
        expect(svg.classes()).toContain('h-8');
        expect(svg.classes()).toContain('w-8');
    });

    it('all sizes have correct classes', () => {
        const sizeMap: Record<string, string[]> = {
            sm: ['h-4', 'w-4'],
            md: ['h-5', 'w-5'],
            lg: ['h-8', 'w-8'],
        };

        for (const [size, expectedClasses] of Object.entries(sizeMap)) {
            const wrapper = mount(BaseSpinner, { props: { size: size as 'sm' | 'md' | 'lg' } });
            const svg = wrapper.find('svg');
            for (const cls of expectedClasses) {
                expect(svg.classes()).toContain(cls);
            }
            expect(svg.classes()).toContain('animate-spin');
            expect(svg.classes()).toContain('text-zinc-400');
        }
    });
});
