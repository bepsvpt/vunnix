import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import axios from 'axios';
import MessageThread from './MessageThread.vue';
import MessageBubble from './MessageBubble.vue';
import MessageComposer from './MessageComposer.vue';
import { useConversationsStore } from '@/stores/conversations';

vi.mock('axios');

// Mock markdown module to avoid Shiki async loading
vi.mock('@/lib/markdown', () => ({
    getMarkdownRenderer: () => ({
        render: (content) => `<p>${content}</p>`,
    }),
    isHighlightReady: () => false,
    onHighlightLoaded: vi.fn(),
}));

let pinia;

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
    vi.restoreAllMocks();
    axios.get.mockResolvedValue({
        data: { data: [], meta: { next_cursor: null } },
    });
});

function mountThread() {
    return mount(MessageThread, {
        global: { plugins: [pinia] },
    });
}

describe('MessageThread', () => {
    it('renders MessageBubble for each message', async () => {
        const store = useConversationsStore();
        store.messages = [
            { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            { id: 'msg-2', role: 'assistant', content: 'Hi there', created_at: '2026-02-15T12:00:01+00:00' },
        ];

        const wrapper = mountThread();
        expect(wrapper.findAllComponents(MessageBubble)).toHaveLength(2);
    });

    it('renders MessageComposer', () => {
        const wrapper = mountThread();
        expect(wrapper.findComponent(MessageComposer).exists()).toBe(true);
    });

    it('shows loading indicator when messagesLoading is true', () => {
        const store = useConversationsStore();
        store.messagesLoading = true;

        const wrapper = mountThread();
        expect(wrapper.find('[data-testid="messages-loading"]').exists()).toBe(true);
    });

    it('shows error message when messagesError is set', () => {
        const store = useConversationsStore();
        store.messagesError = 'Failed to load';

        const wrapper = mountThread();
        expect(wrapper.text()).toContain('Failed to load');
    });

    it('shows empty state when no messages and not loading', () => {
        const store = useConversationsStore();
        store.messages = [];
        store.messagesLoading = false;

        const wrapper = mountThread();
        expect(wrapper.find('[data-testid="empty-thread"]').exists()).toBe(true);
    });

    it('calls streamMessage on composer send event', async () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';
        // Spy on streamMessage to verify it's called instead of sendMessage
        const streamSpy = vi.spyOn(store, 'streamMessage').mockResolvedValue();

        const wrapper = mountThread();
        const composer = wrapper.findComponent(MessageComposer);
        await composer.vm.$emit('send', 'Hello AI');
        await flushPromises();

        expect(streamSpy).toHaveBeenCalledWith('Hello AI');
    });

    it('disables composer while sending', () => {
        const store = useConversationsStore();
        store.sending = true;

        const wrapper = mountThread();
        const composer = wrapper.findComponent(MessageComposer);
        expect(composer.props('disabled')).toBe(true);
    });

    it('disables composer while streaming', () => {
        const store = useConversationsStore();
        store.streaming = true;

        const wrapper = mountThread();
        const composer = wrapper.findComponent(MessageComposer);
        expect(composer.props('disabled')).toBe(true);
    });

    describe('streaming display', () => {
        it('shows typing indicator when streaming starts and no content yet', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.streaming = true;
            store.streamingContent = '';

            const wrapper = mountThread();
            expect(wrapper.find('[data-testid="typing-indicator"]').exists()).toBe(true);
        });

        it('shows streaming bubble with partial content during streaming', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.streaming = true;
            store.streamingContent = 'Partial response';

            const wrapper = mountThread();
            expect(wrapper.find('[data-testid="streaming-bubble"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="streaming-bubble"]').text()).toContain('Partial response');
        });

        it('does not show streaming bubble when not streaming', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.streaming = false;
            store.streamingContent = '';

            const wrapper = mountThread();
            expect(wrapper.find('[data-testid="streaming-bubble"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="typing-indicator"]').exists()).toBe(false);
        });

        it('shows typing indicator even when streamingContent has content', () => {
            const store = useConversationsStore();
            store.messages = [];
            store.streaming = true;
            store.streamingContent = 'Hello';

            const wrapper = mountThread();
            // Typing indicator visible alongside streaming bubble to show "still generating"
            expect(wrapper.find('[data-testid="typing-indicator"]').exists()).toBe(true);
        });

        it('streaming bubble is styled as assistant message (left-aligned)', () => {
            const store = useConversationsStore();
            store.messages = [];
            store.streaming = true;
            store.streamingContent = 'Some text';

            const wrapper = mountThread();
            const bubble = wrapper.find('[data-testid="streaming-bubble"]');
            expect(bubble.exists()).toBe(true);
            // Should be left-aligned like assistant messages
            expect(bubble.element.closest('.justify-start')).toBeTruthy();
        });

        it('does not show empty state when streaming with no persisted messages', () => {
            const store = useConversationsStore();
            store.messages = [];
            store.streaming = true;
            store.streamingContent = '';

            const wrapper = mountThread();
            // Should NOT show the empty thread state when we're actively streaming
            expect(wrapper.find('[data-testid="empty-thread"]').exists()).toBe(false);
        });

        it('shows tool-use indicators during streaming', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Show me auth', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.streaming = true;
            store.activeToolCalls = [
                { id: 'tc-1', tool: 'BrowseRepoTree', input: { path: 'src/' } },
            ];

            const wrapper = mountThread();
            expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(true);
            expect(wrapper.text()).toContain('Browsing');
        });

        it('hides tool-use indicators when not streaming', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.streaming = false;
            store.activeToolCalls = [];

            const wrapper = mountThread();
            expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(false);
        });
    });
});
