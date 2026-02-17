import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/auth';
import { useConversationsStore } from '@/stores/conversations';
import ConversationList from './ConversationList.vue';
import ConversationListItem from './ConversationListItem.vue';

vi.mock('axios');

const mockedAxios = vi.mocked(axios, true);

let pinia: ReturnType<typeof createPinia>;

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
    vi.restoreAllMocks();
    // Default: resolve with empty list so onMounted doesn't error
    mockedAxios.get.mockResolvedValue({
        data: { data: [], meta: { next_cursor: null } },
    });
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
            plugins: [pinia],
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
});
