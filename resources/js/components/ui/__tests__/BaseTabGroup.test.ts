import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BaseTabGroup from '../BaseTabGroup.vue';

const tabs = [
    { key: 'overview', label: 'Overview' },
    { key: 'settings', label: 'Settings' },
    { key: 'logs', label: 'Logs' },
];

describe('baseTabGroup', () => {
    it('renders all tab labels', () => {
        const wrapper = mount(BaseTabGroup, {
            props: { tabs, modelValue: 'overview' },
        });

        const buttons = wrapper.findAll('button');
        expect(buttons).toHaveLength(3);
        expect(buttons[0].text()).toBe('Overview');
        expect(buttons[1].text()).toBe('Settings');
        expect(buttons[2].text()).toBe('Logs');
    });

    it('active tab has active classes', () => {
        const wrapper = mount(BaseTabGroup, {
            props: { tabs, modelValue: 'settings' },
        });

        const activeButton = wrapper.find('[data-testid="tab-settings"]');
        expect(activeButton.classes()).toContain('border-zinc-900');
        expect(activeButton.classes()).toContain('text-zinc-900');
    });

    it('inactive tabs have inactive classes', () => {
        const wrapper = mount(BaseTabGroup, {
            props: { tabs, modelValue: 'settings' },
        });

        const inactiveButton = wrapper.find('[data-testid="tab-overview"]');
        expect(inactiveButton.classes()).toContain('border-transparent');
        expect(inactiveButton.classes()).toContain('text-zinc-500');
    });

    it('clicking a tab emits update:modelValue with the tab key', async () => {
        const wrapper = mount(BaseTabGroup, {
            props: { tabs, modelValue: 'overview' },
        });

        await wrapper.find('[data-testid="tab-logs"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')).toHaveLength(1);
        expect(wrapper.emitted('update:modelValue')![0]).toEqual(['logs']);
    });

    it('renders with data-testid attributes for each tab', () => {
        const wrapper = mount(BaseTabGroup, {
            props: { tabs, modelValue: 'overview' },
        });

        expect(wrapper.find('[data-testid="tab-overview"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tab-settings"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tab-logs"]').exists()).toBe(true);
    });
});
