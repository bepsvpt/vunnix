import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import MessageBubble from './MessageBubble.vue';

// Mock MarkdownContent since it's tested separately
vi.mock('@/lib/markdown', () => ({
    getMarkdownRenderer: () => ({
        render: (content: string) => `<p>${content}</p>`,
    }),
    isHighlightReady: () => false,
    onHighlightLoaded: vi.fn(),
}));

function makeMessage(overrides: Record<string, unknown> = {}) {
    return {
        id: 'msg-1',
        role: 'user',
        content: 'Hello there',
        user_id: 1,
        created_at: '2026-02-15T12:00:00+00:00',
        ...overrides,
    };
}

function mountBubble(message: Record<string, unknown>, props: Record<string, unknown> = {}) {
    return mount(MessageBubble, {
        props: { message, ...props },
    });
}

describe('messageBubble', () => {
    it('renders user message with user styling', () => {
        const wrapper = mountBubble(makeMessage({ role: 'user' }));
        expect(wrapper.find('[data-role="user"]').exists()).toBe(true);
    });

    it('renders assistant message with assistant styling', () => {
        const wrapper = mountBubble(makeMessage({ role: 'assistant' }));
        expect(wrapper.find('[data-role="assistant"]').exists()).toBe(true);
    });

    it('displays user message content as plain text', () => {
        const wrapper = mountBubble(makeMessage({ role: 'user', content: 'Hello there' }));
        expect(wrapper.text()).toContain('Hello there');
    });

    it('renders assistant message with MarkdownContent', () => {
        const wrapper = mountBubble(makeMessage({ role: 'assistant', content: '**bold**' }));
        expect(wrapper.findComponent({ name: 'MarkdownContent' }).exists()).toBe(true);
    });

    it('does not use MarkdownContent for user messages', () => {
        const wrapper = mountBubble(makeMessage({ role: 'user' }));
        expect(wrapper.findComponent({ name: 'MarkdownContent' }).exists()).toBe(false);
    });

    it('displays timestamp', () => {
        const wrapper = mountBubble(makeMessage());
        // Should contain some time representation
        expect(wrapper.find('[data-testid="timestamp"]').exists()).toBe(true);
    });
});
