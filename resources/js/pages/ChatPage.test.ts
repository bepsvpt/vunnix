import type { Router } from 'vue-router';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useConversationsStore } from '@/stores/conversations';
import ChatPage from './ChatPage.vue';

vi.mock('axios');
vi.mock('@/composables/useEcho', () => ({
    whenConnected: vi.fn().mockResolvedValue(undefined),
    onReconnect: vi.fn().mockReturnValue(() => {}),
    getEcho: vi.fn().mockReturnValue({
        private: vi.fn().mockReturnValue({ listen: vi.fn().mockReturnThis(), stopListening: vi.fn().mockReturnThis() }),
        leave: vi.fn(),
    }),
}));

// Mock markdown module to avoid Shiki async loading
vi.mock('@/lib/markdown', () => ({
    getMarkdownRenderer: () => ({
        render: (content: string) => `<p>${content}</p>`,
    }),
    isHighlightReady: (): boolean => false,
    onHighlightLoaded: vi.fn(),
}));

const mockedAxios = vi.mocked(axios, true);

let pinia: ReturnType<typeof createPinia>;
let router: Router;

function createTestRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/chat', name: 'chat', component: ChatPage },
            { path: '/chat/:id', name: 'chat-conversation', component: ChatPage },
        ],
    });
}

beforeEach(async () => {
    pinia = createPinia();
    setActivePinia(pinia);
    vi.restoreAllMocks();

    mockedAxios.get.mockResolvedValue({
        data: { data: [], meta: { next_cursor: null } },
    });

    const auth = useAuthStore();
    auth.setUser({
        id: 1,
        name: 'Test User',
        username: 'testuser',
        avatar_url: null,
        projects: [{ id: 1, gitlab_project_id: 100, name: 'Test Project', slug: 'test-project', roles: ['developer'], permissions: ['chat.send'] }],
    });

    router = createTestRouter();
    router.push('/chat');
    await router.isReady();
});

function mountChatPage() {
    return mount(ChatPage, {
        global: {
            plugins: [pinia, router],
        },
    });
}

describe('chatPage', () => {
    it('renders the chat layout with sidebar and main area', () => {
        const wrapper = mountChatPage();
        expect(wrapper.find('aside').exists()).toBe(true);
        expect(wrapper.find('main').exists()).toBe(true);
    });

    it('shows empty state when no conversation is selected', () => {
        const wrapper = mountChatPage();
        expect(wrapper.text()).toContain('Select a conversation to get started');
    });

    it('does not show MessageThread when no conversation is selected', () => {
        const wrapper = mountChatPage();
        // Empty state should be visible, not the thread
        expect(wrapper.find('main').text()).toContain('Select a conversation');
    });

    it('selects conversation from route param on mount', async () => {
        const store = useConversationsStore();
        const selectSpy = vi.spyOn(store, 'selectConversation').mockResolvedValue();

        router.push('/chat/conv-123');
        await router.isReady();

        mountChatPage();
        await flushPromises();

        expect(selectSpy).toHaveBeenCalledWith('conv-123');
    });

    it('deselects conversation when route has no id', async () => {
        const store = useConversationsStore();
        // Pre-set a selected conversation
        store.selectedId = 'conv-old';
        const selectSpy = vi.spyOn(store, 'selectConversation').mockResolvedValue();

        router.push('/chat');
        await router.isReady();

        mountChatPage();
        await flushPromises();

        expect(selectSpy).toHaveBeenCalledWith(null);
    });

    it('watches route param changes and selects new conversation', async () => {
        const store = useConversationsStore();
        const selectSpy = vi.spyOn(store, 'selectConversation').mockResolvedValue();

        mountChatPage();
        await flushPromises();

        // Navigate to a conversation
        await router.push('/chat/conv-456');
        await flushPromises();

        expect(selectSpy).toHaveBeenCalledWith('conv-456');
    });

    it('watches route param changes from one conversation to another', async () => {
        const store = useConversationsStore();
        const selectSpy = vi.spyOn(store, 'selectConversation').mockResolvedValue();

        // Start at a conversation
        router.push('/chat/conv-1');
        await router.isReady();

        mountChatPage();
        await flushPromises();

        expect(selectSpy).toHaveBeenCalledWith('conv-1');

        // Navigate to different conversation
        await router.push('/chat/conv-2');
        await flushPromises();

        expect(selectSpy).toHaveBeenCalledWith('conv-2');
    });

    it('navigates back to /chat when store clears selectedId', async () => {
        const store = useConversationsStore();
        // Mock selectConversation to actually set selectedId (simulating normal store behavior)
        vi.spyOn(store, 'selectConversation').mockImplementation(async (id) => {
            store.selectedId = id;
        });

        // Start at a conversation
        router.push('/chat/conv-1');
        await router.isReady();

        const replaceSpy = vi.spyOn(router, 'replace');

        mountChatPage();
        await flushPromises();

        // Verify the store has the conversation selected
        expect(store.selectedId).toBe('conv-1');

        // Simulate store clearing selectedId (e.g., archiving the active conversation)
        store.selectedId = null;
        await flushPromises();

        // The watch on store.selectedId should trigger router.replace to /chat
        expect(replaceSpy).toHaveBeenCalledWith({ name: 'chat' });
    });

    it('does not navigate when store clears selectedId and route already at /chat', async () => {
        const store = useConversationsStore();
        vi.spyOn(store, 'selectConversation').mockResolvedValue();

        router.push('/chat');
        await router.isReady();

        mountChatPage();
        await flushPromises();

        const replaceSpy = vi.spyOn(router, 'replace');

        // Setting selectedId to null when route is already /chat should not navigate
        store.selectedId = null;
        await flushPromises();

        expect(replaceSpy).not.toHaveBeenCalled();
    });

    it('skips selectConversation when route id matches store selectedId', async () => {
        const store = useConversationsStore();
        // Pre-set selectedId to match what the route will have
        store.selectedId = 'conv-same';

        router.push('/chat/conv-same');
        await router.isReady();

        const selectSpy = vi.spyOn(store, 'selectConversation').mockResolvedValue();

        mountChatPage();
        await flushPromises();

        // The first watch runs immediately: id='conv-same', store.selectedId='conv-same'
        // So `id && id !== store.selectedId` is false, and `!id && store.selectedId` is false
        // selectConversation should not be called
        expect(selectSpy).not.toHaveBeenCalledWith('conv-same');
    });

    it('shows MessageThread when store has selectedId', async () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-active';
        store.conversations = [{
            id: 'conv-active',
            title: 'Active Conversation',
            project_id: 1,
            user_id: 1,
            archived_at: null,
            created_at: '2026-02-15T10:00:00+00:00',
            updated_at: '2026-02-15T10:00:00+00:00',
            last_message: null,
            projects: [],
        }];
        vi.spyOn(store, 'selectConversation').mockResolvedValue();

        const wrapper = mountChatPage();
        await flushPromises();

        // Empty state should not be visible
        expect(wrapper.text()).not.toContain('Select a conversation to get started');
    });
});
