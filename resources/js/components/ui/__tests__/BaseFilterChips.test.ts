import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BaseFilterChips from '../BaseFilterChips.vue';

const defaultChips = [
    { label: 'All', value: null },
    { label: 'Active', value: 'active' },
    { label: 'Archived', value: 'archived' },
];

function mountChips(props: { chips?: typeof defaultChips; modelValue?: string | null } = {}) {
    return mount(BaseFilterChips, {
        props: {
            chips: props.chips ?? defaultChips,
            modelValue: props.modelValue ?? null,
        },
    });
}

describe('baseFilterChips', () => {
    it('renders all chip labels', () => {
        const wrapper = mountChips();
        const buttons = wrapper.findAll('button');
        expect(buttons).toHaveLength(3);
        expect(buttons[0].text()).toBe('All');
        expect(buttons[1].text()).toBe('Active');
        expect(buttons[2].text()).toBe('Archived');
    });

    it('applies active classes to the chip matching modelValue', () => {
        const wrapper = mountChips({ modelValue: 'active' });
        const activeButton = wrapper.find('[data-testid="chip-active"]');
        expect(activeButton.classes()).toContain('bg-zinc-900');
        expect(activeButton.classes()).toContain('text-white');
    });

    it('applies inactive classes to chips not matching modelValue', () => {
        const wrapper = mountChips({ modelValue: 'active' });
        const allButton = wrapper.find('[data-testid="chip-all"]');
        const archivedButton = wrapper.find('[data-testid="chip-archived"]');

        expect(allButton.classes()).toContain('bg-zinc-100');
        expect(allButton.classes()).toContain('text-zinc-600');

        expect(archivedButton.classes()).toContain('bg-zinc-100');
        expect(archivedButton.classes()).toContain('text-zinc-600');
    });

    it('emits update:modelValue with the chip value when clicked', async () => {
        const wrapper = mountChips({ modelValue: null });
        await wrapper.find('[data-testid="chip-active"]').trigger('click');
        expect(wrapper.emitted('update:modelValue')).toHaveLength(1);
        expect(wrapper.emitted('update:modelValue')![0]).toEqual(['active']);
    });

    it('handles null value chips correctly for "All" filter', async () => {
        const wrapper = mountChips({ modelValue: 'active' });

        // The "All" chip has value: null and should use data-testid="chip-all"
        const allButton = wrapper.find('[data-testid="chip-all"]');
        expect(allButton.exists()).toBe(true);
        expect(allButton.classes()).toContain('bg-zinc-100'); // inactive since modelValue is 'active'

        await allButton.trigger('click');
        expect(wrapper.emitted('update:modelValue')).toHaveLength(1);
        expect(wrapper.emitted('update:modelValue')![0]).toEqual([null]);
    });

    it('applies active classes to null-value chip when modelValue is null', () => {
        const wrapper = mountChips({ modelValue: null });
        const allButton = wrapper.find('[data-testid="chip-all"]');
        expect(allButton.classes()).toContain('bg-zinc-900');
        expect(allButton.classes()).toContain('text-white');
    });
});
