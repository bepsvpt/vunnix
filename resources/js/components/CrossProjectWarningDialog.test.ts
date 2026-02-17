import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CrossProjectWarningDialog from './CrossProjectWarningDialog.vue';

function mountDialog(props: Record<string, unknown> = {}) {
    return mount(CrossProjectWarningDialog, {
        props: {
            existingProjectName: 'Backend API',
            newProjectName: 'Frontend App',
            ...props,
        },
    });
}

describe('crossProjectWarningDialog', () => {
    it('renders warning title and project names', () => {
        const wrapper = mountDialog();

        expect(wrapper.text()).toContain('Cross-Project Visibility Warning');
        expect(wrapper.text()).toContain('Frontend App');
        expect(wrapper.text()).toContain('Backend API');
    });

    it('explains that conversation history becomes visible to new project members', () => {
        const wrapper = mountDialog();

        expect(wrapper.text()).toContain('visible to all members of');
        expect(wrapper.text()).toContain('Frontend App');
    });

    it('warns that the action cannot be undone', () => {
        const wrapper = mountDialog();

        expect(wrapper.text()).toContain('This cannot be undone');
    });

    it('emits confirm event when Continue is clicked', async () => {
        const wrapper = mountDialog();

        const continueBtn = wrapper.findAll('button').find(b => b.text() === 'Continue');
        await continueBtn!.trigger('click');

        expect(wrapper.emitted('confirm')).toBeTruthy();
        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });

    it('emits cancel event when Cancel is clicked', async () => {
        const wrapper = mountDialog();

        const cancelBtn = wrapper.findAll('button').find(b => b.text() === 'Cancel');
        await cancelBtn!.trigger('click');

        expect(wrapper.emitted('cancel')).toBeTruthy();
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('emits cancel event when clicking outside the dialog (overlay)', async () => {
        const wrapper = mountDialog();

        await wrapper.find('.fixed.inset-0').trigger('click');

        expect(wrapper.emitted('cancel')).toBeTruthy();
    });

    it('renders amber warning icon', () => {
        const wrapper = mountDialog();

        // The amber-colored warning icon container
        expect(wrapper.find('.bg-amber-100').exists()).toBe(true);
    });

    it('uses Continue button with amber styling (not primary)', () => {
        const wrapper = mountDialog();

        const continueBtn = wrapper.findAll('button').find(b => b.text() === 'Continue');
        expect(continueBtn!.classes()).toContain('bg-amber-600');
    });

    it('renders with different project names', () => {
        const wrapper = mountDialog({
            existingProjectName: 'Payment Service',
            newProjectName: 'User Portal',
        });

        expect(wrapper.text()).toContain('Payment Service');
        expect(wrapper.text()).toContain('User Portal');
    });
});
