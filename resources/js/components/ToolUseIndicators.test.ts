import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ToolUseIndicators from './ToolUseIndicators.vue';

function mountIndicators(toolCalls: Array<Record<string, unknown>> = []) {
    return mount(ToolUseIndicators, {
        props: { toolCalls },
    });
}

describe('toolUseIndicators', () => {
    it('renders nothing when toolCalls is empty', () => {
        const wrapper = mountIndicators([]);
        expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(false);
    });

    it('renders indicator for BrowseRepoTree with path', () => {
        const wrapper = mountIndicators([
            { id: 'tc-1', tool: 'BrowseRepoTree', input: { path: 'src/services/' } },
        ]);
        expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('🔍');
        expect(wrapper.text()).toContain('Browsing');
        expect(wrapper.text()).toContain('src/services/');
    });

    it('renders indicator for ReadFile with filename', () => {
        const wrapper = mountIndicators([
            { id: 'tc-2', tool: 'ReadFile', input: { file_path: 'src/services/PaymentService.php' } },
        ]);
        expect(wrapper.text()).toContain('📄');
        expect(wrapper.text()).toContain('Reading');
        expect(wrapper.text()).toContain('PaymentService.php');
    });

    it('renders indicator for SearchCode with query', () => {
        const wrapper = mountIndicators([
            { id: 'tc-3', tool: 'SearchCode', input: { query: 'processPayment' } },
        ]);
        expect(wrapper.text()).toContain('🔎');
        expect(wrapper.text()).toContain('Searching for');
        expect(wrapper.text()).toContain('"processPayment"');
    });

    it('renders multiple simultaneous tool calls', () => {
        const wrapper = mountIndicators([
            { id: 'tc-1', tool: 'BrowseRepoTree', input: { path: 'src/' } },
            { id: 'tc-2', tool: 'ReadFile', input: { file_path: 'src/Auth.php' } },
        ]);
        const indicators = wrapper.findAll('[data-testid="tool-use-indicators"] > div > div');
        expect(indicators).toHaveLength(2);
    });

    it('handles unknown tool with fallback display', () => {
        const wrapper = mountIndicators([
            { id: 'tc-4', tool: 'UnknownTool', input: {} },
        ]);
        expect(wrapper.text()).toContain('⚙️');
        expect(wrapper.text()).toContain('UnknownTool');
    });

    it('handles tool_call with no input gracefully', () => {
        const wrapper = mountIndicators([
            { id: 'tc-5', tool: 'ReadFile', input: {} },
        ]);
        expect(wrapper.text()).toContain('📄');
        expect(wrapper.text()).toContain('Reading');
    });

    it('clears when toolCalls becomes empty', async () => {
        const wrapper = mountIndicators([
            { id: 'tc-1', tool: 'ReadFile', input: { file_path: 'test.php' } },
        ]);
        expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(true);

        await wrapper.setProps({ toolCalls: [] });
        expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(false);
    });

    // -- Specific tool indicators for remaining tools (lines 56-62) --

    it('renders indicator for ListIssues with state filter', () => {
        const wrapper = mountIndicators([
            { id: 'tc-6', tool: 'ListIssues', input: { state: 'opened' } },
        ]);
        expect(wrapper.text()).toContain('📋');
        expect(wrapper.text()).toContain('Listing issues');
        expect(wrapper.text()).toContain('(opened)');
    });

    it('renders indicator for ListIssues without state', () => {
        const wrapper = mountIndicators([
            { id: 'tc-7', tool: 'ListIssues', input: {} },
        ]);
        expect(wrapper.text()).toContain('📋');
        expect(wrapper.text()).toContain('Listing issues');
    });

    it('renders indicator for ReadIssue with issue_iid', () => {
        const wrapper = mountIndicators([
            { id: 'tc-8', tool: 'ReadIssue', input: { issue_iid: 42 } },
        ]);
        expect(wrapper.text()).toContain('📋');
        expect(wrapper.text()).toContain('Reading issue');
        expect(wrapper.text()).toContain('#42');
    });

    it('renders indicator for ReadIssue without issue_iid', () => {
        const wrapper = mountIndicators([
            { id: 'tc-9', tool: 'ReadIssue', input: {} },
        ]);
        expect(wrapper.text()).toContain('📋');
        expect(wrapper.text()).toContain('Reading issue');
    });

    it('renders indicator for ListMergeRequests', () => {
        const wrapper = mountIndicators([
            { id: 'tc-10', tool: 'ListMergeRequests', input: {} },
        ]);
        expect(wrapper.text()).toContain('🔀');
        expect(wrapper.text()).toContain('Listing merge requests');
    });

    it('renders indicator for ReadMergeRequest with merge_request_iid', () => {
        const wrapper = mountIndicators([
            { id: 'tc-11', tool: 'ReadMergeRequest', input: { merge_request_iid: 99 } },
        ]);
        expect(wrapper.text()).toContain('🔀');
        expect(wrapper.text()).toContain('Reading merge request');
        expect(wrapper.text()).toContain('!99');
    });

    it('renders indicator for ReadMergeRequest without merge_request_iid', () => {
        const wrapper = mountIndicators([
            { id: 'tc-12', tool: 'ReadMergeRequest', input: {} },
        ]);
        expect(wrapper.text()).toContain('🔀');
        expect(wrapper.text()).toContain('Reading merge request');
    });

    it('renders indicator for ReadMRDiff with merge_request_iid', () => {
        const wrapper = mountIndicators([
            { id: 'tc-13', tool: 'ReadMRDiff', input: { merge_request_iid: 77 } },
        ]);
        expect(wrapper.text()).toContain('🔀');
        expect(wrapper.text()).toContain('Reading MR diff');
        expect(wrapper.text()).toContain('!77');
    });

    it('renders indicator for ReadMRDiff without merge_request_iid', () => {
        const wrapper = mountIndicators([
            { id: 'tc-14', tool: 'ReadMRDiff', input: {} },
        ]);
        expect(wrapper.text()).toContain('🔀');
        expect(wrapper.text()).toContain('Reading MR diff');
    });

    it('renders indicator for DispatchAction', () => {
        const wrapper = mountIndicators([
            { id: 'tc-15', tool: 'DispatchAction', input: { action: 'code_review' } },
        ]);
        expect(wrapper.text()).toContain('🚀');
        expect(wrapper.text()).toContain('Dispatching action');
    });

    // -- Undefined input guard (line 47) --

    it('handles tool_call with undefined input gracefully', () => {
        const wrapper = mountIndicators([
            { id: 'tc-16', tool: 'BrowseRepoTree', input: undefined },
        ]);
        expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('🔍');
        expect(wrapper.text()).toContain('Browsing');
    });

    // -- Multiple simultaneous indicators --

    it('renders all indicators for three simultaneous tool calls', () => {
        const wrapper = mountIndicators([
            { id: 'tc-a', tool: 'ListIssues', input: { state: 'closed' } },
            { id: 'tc-b', tool: 'ReadMergeRequest', input: { merge_request_iid: 5 } },
            { id: 'tc-c', tool: 'SearchCode', input: { query: 'handleError' } },
        ]);
        const indicators = wrapper.findAll('[data-testid="tool-use-indicators"] > div > div');
        expect(indicators).toHaveLength(3);
        expect(wrapper.text()).toContain('(closed)');
        expect(wrapper.text()).toContain('!5');
        expect(wrapper.text()).toContain('"handleError"');
    });

    // -- BrowseRepoTree without path --

    it('renders BrowseRepoTree without path input', () => {
        const wrapper = mountIndicators([
            { id: 'tc-17', tool: 'BrowseRepoTree', input: {} },
        ]);
        expect(wrapper.text()).toContain('🔍');
        expect(wrapper.text()).toContain('Browsing');
    });

    it('renders SearchCode without query input', () => {
        const wrapper = mountIndicators([
            { id: 'tc-18', tool: 'SearchCode', input: {} },
        ]);
        expect(wrapper.text()).toContain('🔎');
        expect(wrapper.text()).toContain('Searching for');
    });

    it('renders ReadFile without file_path input', () => {
        const wrapper = mountIndicators([
            { id: 'tc-19', tool: 'ReadFile', input: {} },
        ]);
        expect(wrapper.text()).toContain('📄');
        expect(wrapper.text()).toContain('Reading');
    });
});
