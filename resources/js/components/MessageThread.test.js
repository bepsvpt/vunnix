import { describe, it, expect, vi, beforeEach } from 'vitest';
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

    it('calls sendMessage on composer send event', async () => {
        const store = useConversationsStore();
        store.selectedId = 1;
        axios.post.mockResolvedValueOnce({
            data: { data: { id: 'msg-new', role: 'user', content: 'Test', created_at: '2026-02-15T12:05:00+00:00' } },
        });

        const wrapper = mountThread();
        const composer = wrapper.findComponent(MessageComposer);
        await composer.vm.$emit('send', 'Test');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/api/v1/conversations/1/messages', {
            content: 'Test',
        });
    });

    it('disables composer while sending', () => {
        const store = useConversationsStore();
        store.sending = true;

        const wrapper = mountThread();
        const composer = wrapper.findComponent(MessageComposer);
        expect(composer.props('disabled')).toBe(true);
    });
});
