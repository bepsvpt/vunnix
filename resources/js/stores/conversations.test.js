import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import axios from 'axios';
import { useConversationsStore } from './conversations';

vi.mock('axios');

beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
});

function makeConversation(overrides = {}) {
    return {
        id: 'conv-1',
        title: 'Test Conversation',
        project_id: 1,
        user_id: 1,
        archived_at: null,
        created_at: '2026-02-15T10:00:00+00:00',
        updated_at: '2026-02-15T12:00:00+00:00',
        last_message: {
            content: 'Hello there',
            role: 'user',
            created_at: '2026-02-15T12:00:00+00:00',
        },
        ...overrides,
    };
}

function makeApiResponse(data, nextCursor = null) {
    return {
        data: {
            data,
            meta: { next_cursor: nextCursor },
        },
    };
}

describe('useConversationsStore', () => {
    describe('initial state', () => {
        it('starts with empty list and default filters', () => {
            const store = useConversationsStore();
            expect(store.conversations).toEqual([]);
            expect(store.loading).toBe(false);
            expect(store.error).toBeNull();
            expect(store.nextCursor).toBeNull();
            expect(store.hasMore).toBe(false);
            expect(store.projectFilter).toBeNull();
            expect(store.searchQuery).toBe('');
            expect(store.showArchived).toBe(false);
            expect(store.selectedId).toBeNull();
            expect(store.selected).toBeNull();
        });
    });

    describe('fetchConversations', () => {
        it('fetches and populates conversations', async () => {
            const conversations = [makeConversation(), makeConversation({ id: 'conv-2' })];
            axios.get.mockResolvedValue(makeApiResponse(conversations, 'cursor-abc'));

            const store = useConversationsStore();
            await store.fetchConversations();

            expect(axios.get).toHaveBeenCalledWith('/api/v1/conversations', {
                params: { per_page: 25 },
            });
            expect(store.conversations).toEqual(conversations);
            expect(store.nextCursor).toBe('cursor-abc');
            expect(store.hasMore).toBe(true);
            expect(store.loading).toBe(false);
            expect(store.error).toBeNull();
        });

        it('sets hasMore to false when no next cursor', async () => {
            axios.get.mockResolvedValue(makeApiResponse([makeConversation()], null));

            const store = useConversationsStore();
            await store.fetchConversations();

            expect(store.hasMore).toBe(false);
            expect(store.nextCursor).toBeNull();
        });

        it('sets loading during fetch', async () => {
            let resolvePromise;
            axios.get.mockReturnValue(new Promise((resolve) => {
                resolvePromise = resolve;
            }));

            const store = useConversationsStore();
            const fetchPromise = store.fetchConversations();

            expect(store.loading).toBe(true);

            resolvePromise(makeApiResponse([]));
            await fetchPromise;

            expect(store.loading).toBe(false);
        });

        it('sets error and empties list on failure', async () => {
            axios.get.mockRejectedValue({
                response: { data: { message: 'Server error' } },
            });

            const store = useConversationsStore();
            await store.fetchConversations();

            expect(store.error).toBe('Server error');
            expect(store.conversations).toEqual([]);
            expect(store.loading).toBe(false);
        });

        it('uses fallback error message when no response message', async () => {
            axios.get.mockRejectedValue(new Error('Network error'));

            const store = useConversationsStore();
            await store.fetchConversations();

            expect(store.error).toBe('Failed to load conversations');
        });

        it('passes project_id when project filter is set', async () => {
            axios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.projectFilter = 42;
            await store.fetchConversations();

            expect(axios.get).toHaveBeenCalledWith('/api/v1/conversations', {
                params: { per_page: 25, project_id: 42 },
            });
        });

        it('passes search when search query is set', async () => {
            axios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.searchQuery = 'deploy';
            await store.fetchConversations();

            expect(axios.get).toHaveBeenCalledWith('/api/v1/conversations', {
                params: { per_page: 25, search: 'deploy' },
            });
        });

        it('passes archived=1 when showArchived is true', async () => {
            axios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.showArchived = true;
            await store.fetchConversations();

            expect(axios.get).toHaveBeenCalledWith('/api/v1/conversations', {
                params: { per_page: 25, archived: 1 },
            });
        });

        it('clears previous error on new fetch', async () => {
            axios.get.mockRejectedValueOnce(new Error('fail'));

            const store = useConversationsStore();
            await store.fetchConversations();
            expect(store.error).toBe('Failed to load conversations');

            axios.get.mockResolvedValueOnce(makeApiResponse([]));
            await store.fetchConversations();
            expect(store.error).toBeNull();
        });
    });

    describe('loadMore', () => {
        it('appends conversations and updates cursor', async () => {
            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];
            store.nextCursor = 'cursor-abc';
            store.hasMore = true;

            axios.get.mockResolvedValue(makeApiResponse(
                [makeConversation({ id: 'conv-2' })],
                'cursor-def'
            ));

            await store.loadMore();

            expect(axios.get).toHaveBeenCalledWith('/api/v1/conversations', {
                params: { cursor: 'cursor-abc', per_page: 25 },
            });
            expect(store.conversations).toHaveLength(2);
            expect(store.conversations[0].id).toBe('conv-1');
            expect(store.conversations[1].id).toBe('conv-2');
            expect(store.nextCursor).toBe('cursor-def');
            expect(store.hasMore).toBe(true);
        });

        it('does not fetch when no cursor', async () => {
            const store = useConversationsStore();
            store.nextCursor = null;

            const callsBefore = axios.get.mock.calls.length;
            await store.loadMore();

            expect(axios.get.mock.calls.length).toBe(callsBefore);
        });

        it('does not fetch when already loading', async () => {
            const store = useConversationsStore();
            store.nextCursor = 'cursor-abc';
            store.loading = true;

            const callsBefore = axios.get.mock.calls.length;
            await store.loadMore();

            expect(axios.get.mock.calls.length).toBe(callsBefore);
        });

        it('sets error on failure', async () => {
            const store = useConversationsStore();
            store.nextCursor = 'cursor-abc';

            axios.get.mockRejectedValue({
                response: { data: { message: 'Page error' } },
            });

            await store.loadMore();

            expect(store.error).toBe('Page error');
        });
    });

    describe('toggleArchive', () => {
        it('removes conversation from list on success', async () => {
            axios.patch.mockResolvedValue({});

            const store = useConversationsStore();
            store.conversations = [
                makeConversation({ id: 'conv-1' }),
                makeConversation({ id: 'conv-2' }),
            ];

            await store.toggleArchive('conv-1');

            expect(axios.patch).toHaveBeenCalledWith('/api/v1/conversations/conv-1/archive');
            expect(store.conversations).toHaveLength(1);
            expect(store.conversations[0].id).toBe('conv-2');
        });

        it('clears selectedId if archived conversation was selected', async () => {
            axios.patch.mockResolvedValue({});

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];
            store.selectedId = 'conv-1';

            await store.toggleArchive('conv-1');

            expect(store.selectedId).toBeNull();
        });

        it('keeps selectedId if a different conversation was archived', async () => {
            axios.patch.mockResolvedValue({});

            const store = useConversationsStore();
            store.conversations = [
                makeConversation({ id: 'conv-1' }),
                makeConversation({ id: 'conv-2' }),
            ];
            store.selectedId = 'conv-1';

            await store.toggleArchive('conv-2');

            expect(store.selectedId).toBe('conv-1');
        });

        it('sets error on failure', async () => {
            axios.patch.mockRejectedValue({
                response: { data: { message: 'Archive failed' } },
            });

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];

            await store.toggleArchive('conv-1');

            expect(store.error).toBe('Archive failed');
            // Conversation stays in list on failure
            expect(store.conversations).toHaveLength(1);
        });
    });

    describe('selectConversation', () => {
        it('sets selectedId', () => {
            const store = useConversationsStore();
            store.selectConversation('conv-1');
            expect(store.selectedId).toBe('conv-1');
        });

        it('resolves selected computed', () => {
            const store = useConversationsStore();
            const conv = makeConversation({ id: 'conv-1' });
            store.conversations = [conv];
            store.selectConversation('conv-1');
            expect(store.selected).toEqual(conv);
        });

        it('returns null for selected when no match', () => {
            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];
            store.selectConversation('conv-999');
            expect(store.selected).toBeNull();
        });
    });

    describe('filter setters trigger fetch', () => {
        it('setProjectFilter sets filter and fetches', async () => {
            axios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.setProjectFilter(5);

            expect(store.projectFilter).toBe(5);
            // Wait for the async fetch to resolve
            await vi.waitFor(() => {
                expect(axios.get).toHaveBeenCalled();
            });
        });

        it('setSearchQuery sets query and fetches', async () => {
            axios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.setSearchQuery('auth');

            expect(store.searchQuery).toBe('auth');
            await vi.waitFor(() => {
                expect(axios.get).toHaveBeenCalled();
            });
        });

        it('setShowArchived toggles and fetches', async () => {
            axios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.setShowArchived(true);

            expect(store.showArchived).toBe(true);
            await vi.waitFor(() => {
                expect(axios.get).toHaveBeenCalled();
            });
        });
    });

    describe('createConversation', () => {
        it('creates conversation and adds to top of list', async () => {
            const newConv = makeConversation({ id: 'conv-new', title: 'New Chat' });
            axios.post.mockResolvedValue({ data: { data: newConv } });

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-old' })];

            const result = await store.createConversation(5);

            expect(axios.post).toHaveBeenCalledWith('/api/v1/conversations', {
                project_id: 5,
            });
            expect(store.conversations).toHaveLength(2);
            expect(store.conversations[0].id).toBe('conv-new');
            expect(store.conversations[1].id).toBe('conv-old');
            expect(result).toEqual(newConv);
        });

        it('selects the newly created conversation', async () => {
            const newConv = makeConversation({ id: 'conv-new' });
            axios.post.mockResolvedValue({ data: { data: newConv } });

            const store = useConversationsStore();
            await store.createConversation(1);

            expect(store.selectedId).toBe('conv-new');
        });

        it('sets error and throws on failure', async () => {
            axios.post.mockRejectedValue({
                response: { data: { message: 'Unauthorized' } },
            });

            const store = useConversationsStore();

            await expect(store.createConversation(1)).rejects.toBeTruthy();
            expect(store.error).toBe('Unauthorized');
        });

        it('uses fallback error message when no response message', async () => {
            axios.post.mockRejectedValue(new Error('Network error'));

            const store = useConversationsStore();

            await expect(store.createConversation(1)).rejects.toBeTruthy();
            expect(store.error).toBe('Failed to create conversation');
        });

        it('clears previous error on new create attempt', async () => {
            const store = useConversationsStore();
            store.error = 'Previous error';

            const newConv = makeConversation({ id: 'conv-new' });
            axios.post.mockResolvedValue({ data: { data: newConv } });

            await store.createConversation(1);

            expect(store.error).toBeNull();
        });
    });

    describe('addProjectToConversation', () => {
        it('adds project and updates conversation in list', async () => {
            const updatedConv = makeConversation({
                id: 'conv-1',
                additional_projects: [{ id: 2, name: 'New Project' }],
            });
            axios.post.mockResolvedValue({ data: { data: updatedConv } });

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];

            const result = await store.addProjectToConversation('conv-1', 2);

            expect(axios.post).toHaveBeenCalledWith(
                '/api/v1/conversations/conv-1/projects',
                { project_id: 2 }
            );
            expect(store.conversations[0].additional_projects).toEqual([
                { id: 2, name: 'New Project' },
            ]);
            expect(result).toEqual(updatedConv);
        });

        it('sets error and throws on failure', async () => {
            axios.post.mockRejectedValue({
                response: { data: { message: 'Cross-project not allowed' } },
            });

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];

            await expect(
                store.addProjectToConversation('conv-1', 2)
            ).rejects.toBeTruthy();
            expect(store.error).toBe('Cross-project not allowed');
        });

        it('uses fallback error message when no response message', async () => {
            axios.post.mockRejectedValue(new Error('Network error'));

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];

            await expect(
                store.addProjectToConversation('conv-1', 2)
            ).rejects.toBeTruthy();
            expect(store.error).toBe('Failed to add project');
        });

        it('does not modify list if conversation not found', async () => {
            const updatedConv = makeConversation({ id: 'conv-999' });
            axios.post.mockResolvedValue({ data: { data: updatedConv } });

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];

            await store.addProjectToConversation('conv-999', 2);

            // Original conversation untouched
            expect(store.conversations).toHaveLength(1);
            expect(store.conversations[0].id).toBe('conv-1');
        });
    });

    describe('$reset', () => {
        it('resets all state to defaults', () => {
            const store = useConversationsStore();
            store.conversations = [makeConversation()];
            store.loading = true;
            store.error = 'some error';
            store.nextCursor = 'cursor-abc';
            store.hasMore = true;
            store.projectFilter = 5;
            store.searchQuery = 'test';
            store.showArchived = true;
            store.selectedId = 'conv-1';

            store.$reset();

            expect(store.conversations).toEqual([]);
            expect(store.loading).toBe(false);
            expect(store.error).toBeNull();
            expect(store.nextCursor).toBeNull();
            expect(store.hasMore).toBe(false);
            expect(store.projectFilter).toBeNull();
            expect(store.searchQuery).toBe('');
            expect(store.showArchived).toBe(false);
            expect(store.selectedId).toBeNull();
        });
    });
});
