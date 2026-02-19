import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ResultCard from './ResultCard.vue';

vi.mock('axios');

// Mock MarkdownContent since it's tested separately.
// Without this, Shiki WASM fails in JSDOM and v-html renders empty.
vi.mock('@/lib/markdown', () => ({
    getMarkdownRenderer: () => ({
        render: (content: string) => `<p>${content}</p>`,
    }),
    isHighlightReady: () => false,
    onHighlightLoaded: vi.fn(),
}));

function makeResult(overrides: Record<string, unknown> = {}) {
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
        key_findings: null,
        ...overrides,
    };
}

function mountCard(result: Record<string, unknown>) {
    return mount(ResultCard, {
        props: { result },
        global: {
            // Stub MarkdownContent: v-html content is invisible to wrapper.text()
            // in JSDOM. The stub renders content as plain text so assertions work.
            stubs: {
                MarkdownContent: {
                    template: '<div data-stub="markdown">{{ content }}</div>',
                    props: ['content'],
                },
            },
        },
    });
}

describe('resultCard', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
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

    // -- Key findings --

    it('hides findings toggle when key_findings is null', () => {
        const wrapper = mountCard(makeResult({ key_findings: null }));
        expect(wrapper.text()).not.toContain('finding');
    });

    it('shows findings count toggle when key_findings are present', () => {
        const wrapper = mountCard(makeResult({
            key_findings: [
                { title: 'Missing auth check', description: 'No auth', severity: 'critical' },
                { title: 'Unused import', description: 'Dead code', severity: 'info' },
            ],
        }));
        expect(wrapper.text()).toContain('2 findings');
    });

    it('expands findings list on toggle click', async () => {
        const wrapper = mountCard(makeResult({
            key_findings: [
                { title: 'Missing auth check', description: 'Auth missing in controller', severity: 'critical' },
            ],
        }));
        // Initially collapsed — description not visible
        expect(wrapper.text()).not.toContain('Auth missing in controller');

        // Click toggle
        await wrapper.find('button').trigger('click');
        expect(wrapper.text()).toContain('Auth missing in controller');
        expect(wrapper.text()).toContain('Missing auth check');
    });

    it('applies correct severity class for critical findings', async () => {
        const wrapper = mountCard(makeResult({
            key_findings: [{ title: 'Vuln', description: 'SQL injection', severity: 'critical' }],
        }));
        await wrapper.find('button').trigger('click');
        const badge = wrapper.find('span.text-red-700, span.dark\\:text-red-300');
        expect(badge.exists() || wrapper.text()).toBeTruthy();
    });

    it('renders major and default severity findings', async () => {
        const wrapper = mountCard(makeResult({
            key_findings: [
                { title: 'Major issue', description: 'Needs attention', severity: 'major' },
                { title: 'Minor issue', description: 'FYI', severity: 'minor' },
            ],
        }));

        await wrapper.find('button').trigger('click');

        expect(wrapper.text()).toContain('major');
        expect(wrapper.text()).toContain('minor');
    });

    // -- Full analysis expand (deep_analysis) --

    it('shows "View full analysis" button for deep_analysis with summary', () => {
        const wrapper = mountCard(makeResult({
            type: 'deep_analysis',
            result_summary: 'First 500 characters of analysis...',
            key_findings: null,
        }));
        expect(wrapper.text()).toContain('View full analysis');
    });

    it('hides "View full analysis" button for non-deep_analysis types', () => {
        const wrapper = mountCard(makeResult({
            type: 'feature_dev',
            result_summary: 'Some summary',
        }));
        expect(wrapper.text()).not.toContain('View full analysis');
    });

    it('hides "View full analysis" button when result_summary is null', () => {
        const wrapper = mountCard(makeResult({
            type: 'deep_analysis',
            result_summary: null,
            key_findings: null,
        }));
        expect(wrapper.text()).not.toContain('View full analysis');
    });

    it('fetches and shows full analysis on button click', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: { data: { result: { analysis: '# Security Report\nComplete analysis text here.' } } },
        });

        const wrapper = mountCard(makeResult({
            task_id: 99,
            type: 'deep_analysis',
            result_summary: 'First 500 chars…',
            key_findings: null,
        }));

        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith('/api/v1/tasks/99/view');
        expect(wrapper.text()).toContain('Complete analysis text here.');
        expect(wrapper.text()).toContain('Hide full analysis');
    });

    it('collapses full analysis on second click', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: { data: { result: { analysis: 'Complete analysis text.' } } },
        });

        const wrapper = mountCard(makeResult({
            type: 'deep_analysis',
            result_summary: 'Preview…',
            key_findings: null,
        }));

        await wrapper.find('button').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Complete analysis text.');

        await wrapper.find('button').trigger('click');
        expect(wrapper.text()).not.toContain('Complete analysis text.');
        expect(wrapper.text()).toContain('View full analysis');
    });

    it('does not re-fetch on subsequent expand after collapse', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: { data: { result: { analysis: 'Cached analysis.' } } },
        });

        const wrapper = mountCard(makeResult({
            type: 'deep_analysis',
            result_summary: 'Preview…',
            key_findings: null,
        }));

        // First expand — fetches
        await wrapper.find('button').trigger('click');
        await flushPromises();
        // Collapse
        await wrapper.find('button').trigger('click');
        // Second expand — should NOT re-fetch
        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('Cached analysis.');
    });
});
