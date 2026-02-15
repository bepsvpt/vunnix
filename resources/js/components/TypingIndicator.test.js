import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import TypingIndicator from './TypingIndicator.vue';

describe('TypingIndicator', () => {
    it('renders with data-testid for integration discovery', () => {
        const wrapper = mount(TypingIndicator);
        expect(wrapper.find('[data-testid="typing-indicator"]').exists()).toBe(true);
    });

    it('renders three animated dots', () => {
        const wrapper = mount(TypingIndicator);
        const dots = wrapper.findAll('.typing-dot');
        expect(dots).toHaveLength(3);
    });

    it('applies bounce animation to dots', () => {
        const wrapper = mount(TypingIndicator);
        const dots = wrapper.findAll('.typing-dot');
        dots.forEach((dot) => {
            expect(dot.classes()).toContain('animate-bounce');
        });
    });

    it('is left-aligned like assistant messages', () => {
        const wrapper = mount(TypingIndicator);
        expect(wrapper.find('.justify-start').exists()).toBe(true);
    });
});
