import type { Router } from 'vue-router';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import ChatPage from '@/pages/ChatPage.vue';
import { useAuthStore } from '@/stores/auth';
import { useConversationsStore } from '@/stores/conversations';
import ConversationList from './ConversationList.vue';
import ConversationListItem from './ConversationListItem.vue';
import NewConversationDialog from './NewConversationDialog.vue';

vi.mock('axios');

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
    // Default: resolve with empty list so onMounted doesn't error
    mockedAxios.get.mockResolvedValue({
        data: { data: [], meta: { next_cursor: null } },
    });
    router = createTestRouter();
    router.push('/chat');
    await router.isReady();
});

function makeConversation(overrides: Record<string, unknown> = {}) {
    return {
        id: 'conv-1',
        title: 'Test Conversation',
        project_id: 1,
        user_id: 1,
        archived_at: null,
        created_at: '2026-02-15T10:00:00+00:00',
        updated_at: '2026-02-15T12:00:00+00:00',
        last_message: {
            content: 'Hello',
            role: 'user',
            created_at: '2026-02-15T12:00:00+00:00',
        },
        ...overrides,
    };
}

function mountList() {
    return mount(ConversationList, {
        global: {
            plugins: [pinia, router],
        },
    });
}

describe('conversationList', () => {
    it('calls fetchConversations on mount', async () => {
        mountList();
        await flushPromises();

        expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/conversations', {
            params: { per_page: 25 },
        });
    });

    it('renders loading spinner when loading with no conversations', async () => {
        // Create a pending promise so loading stays true
        mockedAxios.get.mockReturnValue(new Promise(() => {}));

        const wrapper = mountList();
        // onMounted fires fetchConversations which sets loading=true
        // Need to wait for reactivity to propagate to the DOM
        await wrapper.vm.$nextTick();

        const store = useConversationsStore();
        expect(store.loading).toBe(true);
        expect(wrapper.find('.animate-spin').exists()).toBe(true);
    });

    it('renders empty state when no conversations and not loading', async () => {
        const wrapper = mountList();
        await flushPromises();

        expect(wrapper.text()).toContain('No conversations yet. Start a new one!');
    });

    it('renders search-specific empty state when search is active', async () => {
        const wrapper = mountList();
        await flushPromises();

        const store = useConversationsStore();
        store.searchQuery = 'nonexistent';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('No conversations match your search.');
    });

    it('renders archived-specific empty state when archived filter is on', async () => {
        const wrapper = mountList();
        await flushPromises();

        const store = useConversationsStore();
        store.showArchived = true;
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('No archived conversations.');
    });

    it('renders conversation items from store', async () => {
        mockedAxios.get.mockResolvedValue({
            data: {
                data: [
                    makeConversation({ id: 'conv-1', title: 'First' }),
                    makeConversation({ id: 'conv-2', title: 'Second' }),
                ],
                meta: { next_cursor: null },
            },
        });

        const wrapper = mountList();
        await flushPromises();

        const items = wrapper.findAllComponents(ConversationListItem);
        expect(items.length).toBe(2);
    });

    it('renders project filter dropdown with projects from auth store', async () => {
        const auth = useAuthStore();
        auth.setUser({
            id: 1,
            projects: [
                { id: 1, name: 'Project Alpha', permissions: [] },
                { id: 2, name: 'Project Beta', permissions: [] },
            ],
        });

        const wrapper = mountList();
        await flushPromises();

        const options = wrapper.find('select').findAll('option');
        expect(options.length).toBe(3); // "All projects" + 2 projects
        expect(options[0].text()).toBe('All projects');
        expect(options[1].text()).toBe('Project Alpha');
        expect(options[2].text()).toBe('Project Beta');
    });

    it('resolves project name for conversation items', async () => {
        const auth = useAuthStore();
        auth.setUser({
            id: 1,
            projects: [
                { id: 5, name: 'My App', permissions: [] },
            ],
        });

        mockedAxios.get.mockResolvedValue({
            data: {
                data: [makeConversation({ id: 'conv-1', project_id: 5 })],
                meta: { next_cursor: null },
            },
        });

        const wrapper = mountList();
        await flushPromises();

        const item = wrapper.findComponent(ConversationListItem);
        expect(item.props('projectName')).toBe('My App');
    });

    it('shows Load more button when hasMore is true', async () => {
        mockedAxios.get.mockResolvedValue({
            data: {
                data: [makeConversation()],
                meta: { next_cursor: 'cursor-abc' },
            },
        });

        const wrapper = mountList();
        await flushPromises();

        expect(wrapper.text()).toContain('Load more');
    });

    it('does not show Load more button when hasMore is false', async () => {
        mockedAxios.get.mockResolvedValue({
            data: {
                data: [makeConversation()],
                meta: { next_cursor: null },
            },
        });

        const wrapper = mountList();
        await flushPromises();

        expect(wrapper.text()).not.toContain('Load more');
    });

    it('shows error message when store has error', async () => {
        mockedAxios.get.mockRejectedValue({
            response: { data: { message: 'Something went wrong' } },
        });

        const wrapper = mountList();
        await flushPromises();

        expect(wrapper.text()).toContain('Something went wrong');
    });

    it('renders search input', async () => {
        const wrapper = mountList();
        await flushPromises();

        const input = wrapper.find('input[type="text"]');
        expect(input.exists()).toBe(true);
        expect(input.attributes('placeholder')).toBe('Search conversations...');
    });

    it('renders archive toggle button', async () => {
        const wrapper = mountList();
        await flushPromises();

        const archiveBtn = wrapper.findAll('button').find(b => b.text() === 'Archived');
        expect(archiveBtn).toBeTruthy();
    });

    it('navigates to /chat/:id when selecting a conversation', async () => {
        mockedAxios.get.mockResolvedValue({
            data: {
                data: [makeConversation({ id: 'conv-42' })],
                meta: { next_cursor: null },
            },
        });

        const wrapper = mountList();
        await flushPromises();

        const item = wrapper.findComponent(ConversationListItem);
        item.vm.$emit('select', 'conv-42');
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('chat-conversation');
        expect(router.currentRoute.value.params.id).toBe('conv-42');
    });

    it('navigates to /chat/:id after creating a conversation', async () => {
        const newConv = makeConversation({ id: 'conv-new' });
        mockedAxios.post.mockResolvedValue({ data: { data: newConv } });

        const store = useConversationsStore();
        // Simulate what onCreateConversation does
        const conversation = await store.createConversation(1);
        router.push({ name: 'chat-conversation', params: { id: conversation.id } });
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('chat-conversation');
        expect(router.currentRoute.value.params.id).toBe('conv-new');
    });

    it('navigates to /chat after archiving the selected conversation', async () => {
        mockedAxios.patch.mockResolvedValue({});
        mockedAxios.get.mockResolvedValue({
            data: {
                data: [makeConversation({ id: 'conv-1' })],
                meta: { next_cursor: null },
            },
        });

        // Start on /chat/conv-1
        router.push({ name: 'chat-conversation', params: { id: 'conv-1' } });
        await router.isReady();

        const store = useConversationsStore();
        store.selectedId = 'conv-1';
        store.conversations = [makeConversation({ id: 'conv-1' })];

        // Simulate archive of the selected conversation
        const wasSelected = store.selectedId === 'conv-1';
        await store.toggleArchive('conv-1');
        if (wasSelected) {
            router.push({ name: 'chat' });
        }
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('chat');
        expect(router.currentRoute.value.params.id).toBeUndefined();
    });

    describe('search input debounce', () => {
        it('calls setSearchQuery after debounce delay when typing in search', async () => {
            vi.useFakeTimers();

            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            const setSearchQuerySpy = vi.spyOn(store, 'setSearchQuery');

            const input = wrapper.find('input[type="text"]');
            // Simulate typing by setting the value and triggering input event
            await input.setValue('test query');
            await input.trigger('input');

            // Before debounce fires, setSearchQuery should not have been called
            expect(setSearchQuerySpy).not.toHaveBeenCalled();

            // Advance past the 300ms debounce
            vi.advanceTimersByTime(300);

            expect(setSearchQuerySpy).toHaveBeenCalledWith('test query');

            vi.useRealTimers();
        });

        it('debounces rapid search input — only fires once after last keystroke', async () => {
            vi.useFakeTimers();

            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            const setSearchQuerySpy = vi.spyOn(store, 'setSearchQuery');

            const input = wrapper.find('input[type="text"]');

            // Type quickly (multiple events within debounce window)
            await input.setValue('t');
            await input.trigger('input');
            vi.advanceTimersByTime(100);

            await input.setValue('te');
            await input.trigger('input');
            vi.advanceTimersByTime(100);

            await input.setValue('tes');
            await input.trigger('input');
            vi.advanceTimersByTime(100);

            await input.setValue('test');
            await input.trigger('input');

            // Only 300ms since last keystroke should trigger
            vi.advanceTimersByTime(300);

            // Should only be called once with the final value
            expect(setSearchQuerySpy).toHaveBeenCalledTimes(1);
            expect(setSearchQuerySpy).toHaveBeenCalledWith('test');

            vi.useRealTimers();
        });
    });

    describe('archive toggle', () => {
        it('toggles showArchived in the store when archive button is clicked', async () => {
            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            expect(store.showArchived).toBe(false);

            const archiveBtn = wrapper.findAll('button').find(b => b.text() === 'Archived')!;
            await archiveBtn.trigger('click');
            await flushPromises();

            expect(store.showArchived).toBe(true);
        });

        it('toggles back to non-archived on second click', async () => {
            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            const archiveBtn = wrapper.findAll('button').find(b => b.text() === 'Archived')!;

            // First click: enable archived
            await archiveBtn.trigger('click');
            await flushPromises();
            expect(store.showArchived).toBe(true);

            // Second click: disable archived
            await archiveBtn.trigger('click');
            await flushPromises();
            expect(store.showArchived).toBe(false);
        });

        it('applies active styling when showArchived is true', async () => {
            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            const archiveBtn = wrapper.findAll('button').find(b => b.text() === 'Archived')!;

            // Before toggle: should not have active styling
            expect(archiveBtn.classes()).toContain('border-zinc-300');

            // Toggle archived on
            await archiveBtn.trigger('click');
            await flushPromises();
            await wrapper.vm.$nextTick();

            // After toggle: should have active styling
            const updatedBtn = wrapper.findAll('button').find(b => b.text() === 'Archived')!;
            expect(updatedBtn.classes()).toContain('border-zinc-500');
            expect(store.showArchived).toBe(true);
        });
    });

    describe('selected conversation highlighting', () => {
        it('passes isSelected=true for the conversation matching selectedId', async () => {
            mockedAxios.get.mockResolvedValue({
                data: {
                    data: [
                        makeConversation({ id: 'conv-1', title: 'First' }),
                        makeConversation({ id: 'conv-2', title: 'Second' }),
                    ],
                    meta: { next_cursor: null },
                },
            });

            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            store.selectedId = 'conv-2';
            await wrapper.vm.$nextTick();

            const items = wrapper.findAllComponents(ConversationListItem);
            expect(items[0].props('isSelected')).toBe(false);
            expect(items[1].props('isSelected')).toBe(true);
        });

        it('passes isSelected=false for all items when no conversation is selected', async () => {
            mockedAxios.get.mockResolvedValue({
                data: {
                    data: [
                        makeConversation({ id: 'conv-1' }),
                        makeConversation({ id: 'conv-2' }),
                    ],
                    meta: { next_cursor: null },
                },
            });

            const wrapper = mountList();
            await flushPromises();

            const items = wrapper.findAllComponents(ConversationListItem);
            expect(items[0].props('isSelected')).toBe(false);
            expect(items[1].props('isSelected')).toBe(false);
        });
    });

    describe('new conversation flow', () => {
        it('opens new conversation dialog when "+ New Conversation" button is clicked', async () => {
            const wrapper = mountList();
            await flushPromises();

            // Dialog should not be visible initially
            expect(wrapper.findComponent(NewConversationDialog).exists()).toBe(false);

            // Click the new conversation button
            const newBtn = wrapper.findAll('button').find(b => b.text().includes('New Conversation'))!;
            await newBtn.trigger('click');

            // Dialog should now be visible
            expect(wrapper.findComponent(NewConversationDialog).exists()).toBe(true);
        });

        it('creates conversation and navigates to it on dialog create event', async () => {
            const newConv = makeConversation({ id: 'conv-created' });
            mockedAxios.post.mockResolvedValue({ data: { data: newConv } });

            const wrapper = mountList();
            await flushPromises();

            // Open the dialog
            const newBtn = wrapper.findAll('button').find(b => b.text().includes('New Conversation'))!;
            await newBtn.trigger('click');

            // Emit create event from the dialog
            const dialog = wrapper.findComponent(NewConversationDialog);
            dialog.vm.$emit('create', 1);
            await flushPromises();

            expect(mockedAxios.post).toHaveBeenCalledWith('/api/v1/conversations', { project_id: 1 });
            expect(router.currentRoute.value.name).toBe('chat-conversation');
            expect(router.currentRoute.value.params.id).toBe('conv-created');
        });

        it('closes dialog after successful conversation creation', async () => {
            const newConv = makeConversation({ id: 'conv-new-2' });
            mockedAxios.post.mockResolvedValue({ data: { data: newConv } });

            const wrapper = mountList();
            await flushPromises();

            // Open dialog
            const newBtn = wrapper.findAll('button').find(b => b.text().includes('New Conversation'))!;
            await newBtn.trigger('click');
            expect(wrapper.findComponent(NewConversationDialog).exists()).toBe(true);

            // Trigger creation
            wrapper.findComponent(NewConversationDialog).vm.$emit('create', 1);
            await flushPromises();

            // Dialog should be closed
            expect(wrapper.findComponent(NewConversationDialog).exists()).toBe(false);
        });

        it('keeps dialog open when conversation creation fails', async () => {
            mockedAxios.post.mockRejectedValue({
                response: { data: { message: 'Create failed' } },
            });

            const wrapper = mountList();
            await flushPromises();

            // Open dialog
            const newBtn = wrapper.findAll('button').find(b => b.text().includes('New Conversation'))!;
            await newBtn.trigger('click');

            // Trigger creation that fails
            wrapper.findComponent(NewConversationDialog).vm.$emit('create', 1);
            await flushPromises();

            // Dialog should still be open (error is in the store)
            expect(wrapper.findComponent(NewConversationDialog).exists()).toBe(true);
        });

        it('closes dialog on close event', async () => {
            const wrapper = mountList();
            await flushPromises();

            // Open dialog
            const newBtn = wrapper.findAll('button').find(b => b.text().includes('New Conversation'))!;
            await newBtn.trigger('click');
            expect(wrapper.findComponent(NewConversationDialog).exists()).toBe(true);

            // Emit close event
            wrapper.findComponent(NewConversationDialog).vm.$emit('close');
            await wrapper.vm.$nextTick();

            expect(wrapper.findComponent(NewConversationDialog).exists()).toBe(false);
        });
    });

    describe('load more pagination', () => {
        it('calls store.loadMore() when load more button is clicked', async () => {
            mockedAxios.get.mockResolvedValue({
                data: {
                    data: [makeConversation()],
                    meta: { next_cursor: 'cursor-abc' },
                },
            });

            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            const loadMoreSpy = vi.spyOn(store, 'loadMore').mockResolvedValue();

            const loadMoreBtn = wrapper.findAll('button').find(b => b.text().includes('Load more'))!;
            await loadMoreBtn.trigger('click');

            expect(loadMoreSpy).toHaveBeenCalledOnce();
        });

        it('shows "Loading..." text on load more button when loading', async () => {
            mockedAxios.get.mockResolvedValue({
                data: {
                    data: [makeConversation()],
                    meta: { next_cursor: 'cursor-abc' },
                },
            });

            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            store.loading = true;
            await wrapper.vm.$nextTick();

            const loadMoreBtn = wrapper.findAll('button').find(b => b.text().includes('Loading...'));
            expect(loadMoreBtn).toBeTruthy();
            expect(loadMoreBtn!.attributes('disabled')).toBeDefined();
        });
    });

    describe('archive via conversation item', () => {
        it('archives the selected conversation and navigates to /chat', async () => {
            mockedAxios.patch.mockResolvedValue({});
            mockedAxios.get.mockResolvedValue({
                data: {
                    data: [makeConversation({ id: 'conv-1' })],
                    meta: { next_cursor: null },
                },
            });

            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            store.selectedId = 'conv-1';

            // Emit archive from the item
            const item = wrapper.findComponent(ConversationListItem);
            item.vm.$emit('archive', 'conv-1');
            await flushPromises();

            expect(mockedAxios.patch).toHaveBeenCalledWith('/api/v1/conversations/conv-1/archive');
            expect(router.currentRoute.value.name).toBe('chat');
        });

        it('does not navigate after archiving a non-selected conversation', async () => {
            mockedAxios.patch.mockResolvedValue({});
            mockedAxios.get.mockResolvedValue({
                data: {
                    data: [
                        makeConversation({ id: 'conv-1' }),
                        makeConversation({ id: 'conv-2' }),
                    ],
                    meta: { next_cursor: null },
                },
            });

            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            store.selectedId = 'conv-1';

            // Archive a non-selected conversation
            const items = wrapper.findAllComponents(ConversationListItem);
            items[1].vm.$emit('archive', 'conv-2');
            await flushPromises();

            // Should stay on current route (not navigate to /chat)
            expect(router.currentRoute.value.path).toBe('/chat');
        });
    });

    describe('project name resolution edge case', () => {
        it('returns empty string for unknown project IDs', async () => {
            const auth = useAuthStore();
            auth.setUser({
                id: 1,
                projects: [
                    { id: 1, name: 'Known Project', permissions: [] },
                ],
            });

            mockedAxios.get.mockResolvedValue({
                data: {
                    data: [makeConversation({ id: 'conv-1', project_id: 999 })],
                    meta: { next_cursor: null },
                },
            });

            const wrapper = mountList();
            await flushPromises();

            const item = wrapper.findComponent(ConversationListItem);
            expect(item.props('projectName')).toBe('');
        });
    });

    describe('error state', () => {
        it('displays error from the store at the bottom of the list', async () => {
            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            store.error = 'Network error occurred';
            await wrapper.vm.$nextTick();

            const errorDiv = wrapper.find('.text-red-600');
            expect(errorDiv.exists()).toBe(true);
            expect(errorDiv.text()).toBe('Network error occurred');
        });

        it('hides error div when store has no error', async () => {
            const wrapper = mountList();
            await flushPromises();

            const store = useConversationsStore();
            expect(store.error).toBeNull();

            const errorDiv = wrapper.find('.text-red-600');
            expect(errorDiv.exists()).toBe(false);
        });
    });
});
