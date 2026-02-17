import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import ResultCard from './ResultCard.vue';

function makeResult(overrides = {}) {
    return {
        task_id: 42,
        status: 'completed',
        type: 'feature_dev',
        title: 'Add Stripe payment flow',
        mr_iid: 123,
        issue_iid: null,
        branch: 'ai/payment-feature',
        target_branch: 'main',
        files_changed: [
            { path: 'app/Http/Controllers/PaymentController.php', action: 'created', summary: 'Payment controller with checkout endpoint' },
            { path: 'app/Services/StripeService.php', action: 'created', summary: 'Stripe integration service' },
        ],
        result_summary: 'Created PaymentController and StripeService with checkout flow',
        project_id: 1,
        gitlab_url: 'https://gitlab.example.com/project',
        screenshot: null,
        error_reason: null,
        ...overrides,
    };
}

function mountCard(result) {
    return mount(ResultCard, {
        props: { result },
    });
}

describe('resultCard', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    // -- Success state --

    it('renders the card with data-testid', () => {
        const wrapper = mountCard(makeResult());
        expect(wrapper.find('[data-testid="result-card"]').exists()).toBe(true);
    });

    it('shows success indicator for completed tasks', () => {
        const wrapper = mountCard(makeResult({ status: 'completed' }));
        expect(wrapper.text()).toContain('✅');
    });

    it('displays task title', () => {
        const wrapper = mountCard(makeResult({ title: 'Fix login bug' }));
        expect(wrapper.text()).toContain('Fix login bug');
    });

    it('shows MR link when mr_iid is present', () => {
        const wrapper = mountCard(makeResult({ mr_iid: 456 }));
        const link = wrapper.find('[data-testid="artifact-link"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('!456');
    });

    it('shows Issue link when issue_iid is present and no mr_iid', () => {
        const wrapper = mountCard(makeResult({ mr_iid: null, issue_iid: 78 }));
        const link = wrapper.find('[data-testid="artifact-link"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('#78');
    });

    it('displays files changed count', () => {
        const wrapper = mountCard(makeResult());
        expect(wrapper.text()).toContain('2');
        expect(wrapper.text()).toMatch(/files?\s*changed/i);
    });

    it('shows result summary text', () => {
        const wrapper = mountCard(makeResult({ result_summary: 'Added payment flow' }));
        expect(wrapper.text()).toContain('Added payment flow');
    });

    it('shows branch info for feature_dev', () => {
        const wrapper = mountCard(makeResult({
            type: 'feature_dev',
            branch: 'ai/payment-feature',
            target_branch: 'main',
        }));
        expect(wrapper.text()).toContain('ai/payment-feature');
        expect(wrapper.text()).toContain('main');
    });

    it('displays action type badge', () => {
        const wrapper = mountCard(makeResult({ type: 'feature_dev' }));
        expect(wrapper.find('[data-testid="result-type-badge"]').exists()).toBe(true);
    });

    // -- Failure state --

    it('shows failure indicator for failed tasks', () => {
        const wrapper = mountCard(makeResult({ status: 'failed' }));
        expect(wrapper.text()).toContain('❌');
    });

    it('shows error reason for failed tasks', () => {
        const wrapper = mountCard(makeResult({
            status: 'failed',
            error_reason: 'Schema validation failed: branch is required',
        }));
        expect(wrapper.find('[data-testid="error-reason"]').text()).toContain('Schema validation failed');
    });

    it('hides error reason when not failed', () => {
        const wrapper = mountCard(makeResult({ status: 'completed', error_reason: null }));
        expect(wrapper.find('[data-testid="error-reason"]').exists()).toBe(false);
    });

    // -- UI adjustment screenshot --

    it('shows screenshot for ui_adjustment tasks', () => {
        const wrapper = mountCard(makeResult({
            type: 'ui_adjustment',
            screenshot: 'iVBORw0KGgoAAAANSUhEUg==',
        }));
        const img = wrapper.find('[data-testid="result-screenshot"] img');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toContain('data:image/png;base64,');
    });

    it('hides screenshot for non-ui_adjustment tasks', () => {
        const wrapper = mountCard(makeResult({
            type: 'feature_dev',
            screenshot: 'iVBORw0KGgoAAAANSUhEUg==',
        }));
        expect(wrapper.find('[data-testid="result-screenshot"]').exists()).toBe(false);
    });

    it('hides screenshot when null', () => {
        const wrapper = mountCard(makeResult({
            type: 'ui_adjustment',
            screenshot: null,
        }));
        expect(wrapper.find('[data-testid="result-screenshot"]').exists()).toBe(false);
    });

    // -- Edge cases --

    it('handles missing files_changed gracefully', () => {
        const wrapper = mountCard(makeResult({ files_changed: null }));
        expect(wrapper.text()).not.toMatch(/files?\s*changed/i);
    });

    it('hides artifact link when neither mr_iid nor issue_iid present', () => {
        const wrapper = mountCard(makeResult({ mr_iid: null, issue_iid: null }));
        expect(wrapper.find('[data-testid="artifact-link"]').exists()).toBe(false);
    });

    it('prefers MR link over Issue link when both present', () => {
        const wrapper = mountCard(makeResult({ mr_iid: 123, issue_iid: 45 }));
        expect(wrapper.find('[data-testid="artifact-link"]').text()).toContain('!123');
    });
});
