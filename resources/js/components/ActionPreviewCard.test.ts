import { mount } from '@vue/test-utils';
import MarkdownIt from 'markdown-it';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ActionPreviewCard from './ActionPreviewCard.vue';

// Mock the markdown module to avoid async Shiki loading in tests
const testMd = new MarkdownIt({ html: false, linkify: true, typographer: true });

vi.mock('@/lib/markdown', () => ({
    getMarkdownRenderer: () => testMd,
    isHighlightReady: (): boolean => false,
    onHighlightLoaded: vi.fn(),
}));

function makeAction(overrides: Record<string, unknown> = {}) {
    return {
        id: 'preview-1',
        action_type: 'create_issue',
        project_id: 42,
        title: 'Add payment integration',
        description: 'Implement Stripe payment flow with checkout and webhooks',
        ...overrides,
    };
}

function mountCard(action: Record<string, unknown>) {
    return mount(ActionPreviewCard, {
        props: { action },
    });
}

describe('actionPreviewCard', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    // -- Rendering --

    it('renders the card with data-testid', () => {
        const wrapper = mountCard(makeAction());
        expect(wrapper.find('[data-testid="action-preview-card"]').exists()).toBe(true);
    });

    it('displays the action title', () => {
        const wrapper = mountCard(makeAction({ title: 'Fix login bug' }));
        expect(wrapper.text()).toContain('Fix login bug');
    });

    it('displays description preview', () => {
        const wrapper = mountCard(makeAction({ description: 'A long description here' }));
        expect(wrapper.text()).toContain('A long description here');
    });

    it('displays project ID', () => {
        const wrapper = mountCard(makeAction({ project_id: 99 }));
        expect(wrapper.text()).toContain('#99');
    });

    it('shows Confirm and Cancel buttons', () => {
        const wrapper = mountCard(makeAction());
        expect(wrapper.find('[data-testid="confirm-btn"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="cancel-btn"]').exists()).toBe(true);
    });

    // -- Action type specific: Create Issue --

    it('shows Issue badge for create_issue', () => {
        const wrapper = mountCard(makeAction({ action_type: 'create_issue' }));
        expect(wrapper.find('[data-testid="action-type-badge"]').text()).toContain('Create Issue');
    });

    it('shows assignee for create_issue when provided', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'create_issue',
            assignee_id: 7,
        }));
        expect(wrapper.text()).toContain('Assignee');
        expect(wrapper.text()).toContain('User #7');
    });

    it('hides assignee for create_issue when not provided', () => {
        const wrapper = mountCard(makeAction({ action_type: 'create_issue' }));
        expect(wrapper.text()).not.toContain('Assignee');
    });

    it('shows labels for create_issue', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'create_issue',
            labels: ['feature', 'ai::created'],
        }));
        expect(wrapper.text()).toContain('Labels');
        expect(wrapper.text()).toContain('feature');
        expect(wrapper.text()).toContain('ai::created');
    });

    // -- Action type specific: Implement Feature --

    it('shows Feature badge for implement_feature', () => {
        const wrapper = mountCard(makeAction({ action_type: 'implement_feature' }));
        expect(wrapper.find('[data-testid="action-type-badge"]').text()).toContain('Implement Feature');
    });

    it('shows branch info for implement_feature', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'implement_feature',
            branch_name: 'ai/payment-feature',
            target_branch: 'main',
        }));
        expect(wrapper.text()).toContain('ai/payment-feature');
        expect(wrapper.text()).toContain('Branch');
        expect(wrapper.text()).toContain('Target');
        expect(wrapper.text()).toContain('main');
    });

    // -- Action type specific: UI Adjustment --

    it('shows UI Adjustment badge for ui_adjustment', () => {
        const wrapper = mountCard(makeAction({ action_type: 'ui_adjustment' }));
        expect(wrapper.find('[data-testid="action-type-badge"]').text()).toContain('UI Adjustment');
    });

    it('shows files list for ui_adjustment', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'ui_adjustment',
            branch_name: 'ai/fix-padding',
            files: ['src/components/Card.vue', 'src/styles/tokens.scss'],
        }));
        expect(wrapper.text()).toContain('ai/fix-padding');
        expect(wrapper.text()).toContain('Files');
        expect(wrapper.text()).toContain('Card.vue');
        expect(wrapper.text()).toContain('tokens.scss');
    });

    // -- Action type specific: Create MR --

    it('shows Create MR badge for create_mr', () => {
        const wrapper = mountCard(makeAction({ action_type: 'create_mr' }));
        expect(wrapper.find('[data-testid="action-type-badge"]').text()).toContain('Create MR');
    });

    it('shows merge direction for create_mr', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'create_mr',
            branch_name: 'ai/feature-branch',
            target_branch: 'main',
        }));
        expect(wrapper.text()).toContain('ai/feature-branch');
        expect(wrapper.text()).toContain('\u2192');
        expect(wrapper.text()).toContain('main');
    });

    it('defaults target_branch to main for create_mr', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'create_mr',
            branch_name: 'ai/feature-branch',
        }));
        expect(wrapper.text()).toContain('main');
    });

    // -- Unknown action type --

    it('handles unknown action type with fallback', () => {
        const wrapper = mountCard(makeAction({ action_type: 'custom_action' }));
        expect(wrapper.find('[data-testid="action-type-badge"]').text()).toContain('custom_action');
    });

    // -- Button events --

    it('emits confirm event when Confirm is clicked', async () => {
        const wrapper = mountCard(makeAction());
        await wrapper.find('[data-testid="confirm-btn"]').trigger('click');
        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });

    it('emits cancel event when Cancel is clicked', async () => {
        const wrapper = mountCard(makeAction());
        await wrapper.find('[data-testid="cancel-btn"]').trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    // -- Description truncation --

    it('truncates long descriptions to ~200 chars', () => {
        const longDesc = 'A'.repeat(300);
        const wrapper = mountCard(makeAction({ description: longDesc }));
        const descEl = wrapper.find('[data-testid="description-preview"]');
        expect(descEl.text().length).toBeLessThan(210);
        expect(descEl.text()).toContain('\u2026');
    });

    it('does not truncate short descriptions', () => {
        const shortDesc = 'Short description';
        const wrapper = mountCard(makeAction({ description: shortDesc }));
        const descEl = wrapper.find('[data-testid="description-preview"]');
        expect(descEl.text()).toBe('Short description');
        expect(descEl.text()).not.toContain('\u2026');
    });

    // -- Cross-cutting: fields don't leak between types --

    it('does not show branch fields for create_issue', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'create_issue',
            branch_name: 'ai/something',
        }));
        // Branch name should not appear because the Issue template doesn't render it
        expect(wrapper.text()).not.toContain('Branch');
    });

    it('does not show assignee for implement_feature', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'implement_feature',
            assignee_id: 7,
        }));
        expect(wrapper.text()).not.toContain('Assignee');
    });

    // -- Disabled state --

    it('shows Dispatching text and hides buttons when disabled', () => {
        const wrapper = mount(ActionPreviewCard, {
            props: { action: makeAction(), disabled: true },
        });
        expect(wrapper.text()).toContain('Dispatching');
        expect(wrapper.find('[data-testid="confirm-btn"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="cancel-btn"]').exists()).toBe(false);
    });

    it('shows buttons and no Dispatching text when not disabled', () => {
        const wrapper = mount(ActionPreviewCard, {
            props: { action: makeAction(), disabled: false },
        });
        expect(wrapper.text()).not.toContain('Dispatching');
        expect(wrapper.find('[data-testid="confirm-btn"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="cancel-btn"]').exists()).toBe(true);
    });
});
