import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ToolUseIndicators from './ToolUseIndicators.vue';

function mountIndicators(toolCalls = []) {
    return mount(ToolUseIndicators, {
        props: { toolCalls },
    });
}

describe('ToolUseIndicators', () => {
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
});
