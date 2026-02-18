import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BaseEmptyState from '../BaseEmptyState.vue';

describe('baseEmptyState', () => {
    it('renders with all slots', () => {
        const wrapper = mount(BaseEmptyState, {
            slots: {
                icon: '<span>ICON</span>',
                title: 'No items found',
                description: 'Try adjusting your search or filters.',
                action: '<button>Create new</button>',
            },
        });

        expect(wrapper.text()).toContain('ICON');
        expect(wrapper.text()).toContain('No items found');
        expect(wrapper.text()).toContain('Try adjusting your search or filters.');
        expect(wrapper.text()).toContain('Create new');
    });

    it('renders gracefully with no slots', () => {
        const wrapper = mount(BaseEmptyState);

        expect(wrapper.find('div').exists()).toBe(true);
        expect(wrapper.find('h3').exists()).toBe(false);
        expect(wrapper.find('p').exists()).toBe(false);
    });

    it('renders with only title and description', () => {
        const wrapper = mount(BaseEmptyState, {
            slots: {
                title: 'Empty state',
                description: 'Nothing here yet.',
            },
        });

        expect(wrapper.find('h3').text()).toBe('Empty state');
        expect(wrapper.find('p').text()).toBe('Nothing here yet.');
        expect(wrapper.find('.rounded-full').exists()).toBe(false);
        // Only the root div should exist — no icon or action wrapper divs
        expect(wrapper.findAll('div')).toHaveLength(1);
    });

    it('icon slot content is wrapped in rounded circle div', () => {
        const wrapper = mount(BaseEmptyState, {
            slots: {
                icon: '<span data-testid="my-icon">STAR</span>',
            },
        });

        const iconWrapper = wrapper.find('.rounded-full');
        expect(iconWrapper.exists()).toBe(true);
        expect(iconWrapper.classes()).toContain('w-12');
        expect(iconWrapper.classes()).toContain('h-12');
        expect(iconWrapper.classes()).toContain('bg-zinc-100');
        expect(iconWrapper.find('[data-testid="my-icon"]').text()).toBe('STAR');
    });

    it('action slot content is rendered', () => {
        const wrapper = mount(BaseEmptyState, {
            slots: {
                action: '<button class="btn-primary">Take action</button>',
            },
        });

        const button = wrapper.find('.btn-primary');
        expect(button.exists()).toBe(true);
        expect(button.text()).toBe('Take action');
    });
});
