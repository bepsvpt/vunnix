import type { Mock } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useConversationsStore } from './conversations';

vi.mock('axios');

const mockedAxios = vi.mocked(axios, true);

// Mock useEcho for Reverb channel subscription tests (T69)
// vi.hoisted() ensures these are available inside the hoisted vi.mock() factory
const { mockListenFn, mockStopListeningFn, mockChannel, mockEchoInstance } = vi.hoisted(() => {
    const mockListenFn: Mock = vi.fn().mockReturnThis();
    const mockStopListeningFn: Mock = vi.fn().mockReturnThis();
    const mockChannel = { listen: mockListenFn, stopListening: mockStopListeningFn };
    const mockEchoInstance = {
        private: vi.fn().mockReturnValue(mockChannel) as Mock,
        leave: vi.fn() as Mock,
    };
    return { mockListenFn, mockStopListeningFn, mockChannel, mockEchoInstance };
});
vi.mock('@/composables/useEcho', () => ({
    getEcho: vi.fn().mockReturnValue(mockEchoInstance),
}));

beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
    // Re-initialize echo mocks after restoreAllMocks clears implementations
    mockListenFn.mockClear().mockReturnThis();
    mockStopListeningFn.mockClear().mockReturnThis();
    mockEchoInstance.private.mockClear().mockReturnValue(mockChannel);
    mockEchoInstance.leave.mockClear();
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
            content: 'Hello there',
            role: 'user',
            created_at: '2026-02-15T12:00:00+00:00',
        },
        ...overrides,
    };
}

function makeApiResponse(data: unknown[], nextCursor: string | null = null) {
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
            mockedAxios.get.mockResolvedValue(makeApiResponse(conversations, 'cursor-abc'));

            const store = useConversationsStore();
            await store.fetchConversations();

            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/conversations', {
                params: { per_page: 25 },
            });
            expect(store.conversations).toEqual(conversations);
            expect(store.nextCursor).toBe('cursor-abc');
            expect(store.hasMore).toBe(true);
            expect(store.loading).toBe(false);
            expect(store.error).toBeNull();
        });

        it('sets hasMore to false when no next cursor', async () => {
            mockedAxios.get.mockResolvedValue(makeApiResponse([makeConversation()], null));

            const store = useConversationsStore();
            await store.fetchConversations();

            expect(store.hasMore).toBe(false);
            expect(store.nextCursor).toBeNull();
        });

        it('sets loading during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(new Promise((resolve) => {
                resolvePromise = resolve;
            }) as never);

            const store = useConversationsStore();
            const fetchPromise = store.fetchConversations();

            expect(store.loading).toBe(true);

            resolvePromise!(makeApiResponse([]));
            await fetchPromise;

            expect(store.loading).toBe(false);
        });

        it('sets error and empties list on failure', async () => {
            mockedAxios.get.mockRejectedValue({
                response: { data: { message: 'Server error' } },
            });

            const store = useConversationsStore();
            await store.fetchConversations();

            expect(store.error).toBe('Server error');
            expect(store.conversations).toEqual([]);
            expect(store.loading).toBe(false);
        });

        it('uses fallback error message when no response message', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            const store = useConversationsStore();
            await store.fetchConversations();

            expect(store.error).toBe('Failed to load conversations');
        });

        it('passes project_id when project filter is set', async () => {
            mockedAxios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.projectFilter = 42;
            await store.fetchConversations();

            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/conversations', {
                params: { per_page: 25, project_id: 42 },
            });
        });

        it('passes search when search query is set', async () => {
            mockedAxios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.searchQuery = 'deploy';
            await store.fetchConversations();

            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/conversations', {
                params: { per_page: 25, search: 'deploy' },
            });
        });

        it('passes archived=1 when showArchived is true', async () => {
            mockedAxios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.showArchived = true;
            await store.fetchConversations();

            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/conversations', {
                params: { per_page: 25, archived: 1 },
            });
        });

        it('clears previous error on new fetch', async () => {
            mockedAxios.get.mockRejectedValueOnce(new Error('fail'));

            const store = useConversationsStore();
            await store.fetchConversations();
            expect(store.error).toBe('Failed to load conversations');

            mockedAxios.get.mockResolvedValueOnce(makeApiResponse([]));
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

            mockedAxios.get.mockResolvedValue(makeApiResponse(
                [makeConversation({ id: 'conv-2' })],
                'cursor-def',
            ));

            await store.loadMore();

            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/conversations', {
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

            const callsBefore = mockedAxios.get.mock.calls.length;
            await store.loadMore();

            expect(mockedAxios.get.mock.calls.length).toBe(callsBefore);
        });

        it('does not fetch when already loading', async () => {
            const store = useConversationsStore();
            store.nextCursor = 'cursor-abc';
            store.loading = true;

            const callsBefore = mockedAxios.get.mock.calls.length;
            await store.loadMore();

            expect(mockedAxios.get.mock.calls.length).toBe(callsBefore);
        });

        it('sets error on failure', async () => {
            const store = useConversationsStore();
            store.nextCursor = 'cursor-abc';

            mockedAxios.get.mockRejectedValue({
                response: { data: { message: 'Page error' } },
            });

            await store.loadMore();

            expect(store.error).toBe('Page error');
        });
    });

    describe('toggleArchive', () => {
        it('removes conversation from list on success', async () => {
            mockedAxios.patch.mockResolvedValue({});

            const store = useConversationsStore();
            store.conversations = [
                makeConversation({ id: 'conv-1' }),
                makeConversation({ id: 'conv-2' }),
            ];

            await store.toggleArchive('conv-1');

            expect(mockedAxios.patch).toHaveBeenCalledWith('/api/v1/conversations/conv-1/archive');
            expect(store.conversations).toHaveLength(1);
            expect(store.conversations[0].id).toBe('conv-2');
        });

        it('clears selectedId if archived conversation was selected', async () => {
            mockedAxios.patch.mockResolvedValue({});

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];
            store.selectedId = 'conv-1';

            await store.toggleArchive('conv-1');

            expect(store.selectedId).toBeNull();
        });

        it('keeps selectedId if a different conversation was archived', async () => {
            mockedAxios.patch.mockResolvedValue({});

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
            mockedAxios.patch.mockRejectedValue({
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
        it('sets selectedId and triggers fetchMessages', async () => {
            mockedAxios.get.mockResolvedValueOnce({
                data: { data: { id: 'conv-1', messages: [{ id: 'msg-1', role: 'user', content: 'Hi', created_at: '2026-02-15T12:00:00+00:00' }] } },
            });

            const store = useConversationsStore();
            await store.selectConversation('conv-1');
            expect(store.selectedId).toBe('conv-1');
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/conversations/conv-1');
        });

        it('resolves selected computed', async () => {
            mockedAxios.get.mockResolvedValueOnce({
                data: { data: { id: 'conv-1', messages: [] } },
            });

            const store = useConversationsStore();
            const conv = makeConversation({ id: 'conv-1' });
            store.conversations = [conv];
            await store.selectConversation('conv-1');
            expect(store.selected).toEqual(conv);
        });

        it('returns null for selected when no match', async () => {
            mockedAxios.get.mockResolvedValueOnce({
                data: { data: { id: 'conv-999', messages: [] } },
            });

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];
            await store.selectConversation('conv-999');
            expect(store.selected).toBeNull();
        });

        it('clears messages when selecting null', async () => {
            const store = useConversationsStore();
            store.messages = [{ id: 'msg-1' }];
            await store.selectConversation(null);

            expect(store.selectedId).toBeNull();
            expect(store.messages).toEqual([]);
        });
    });

    describe('filter setters trigger fetch', () => {
        it('setProjectFilter sets filter and fetches', async () => {
            mockedAxios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.setProjectFilter(5);

            expect(store.projectFilter).toBe(5);
            // Wait for the async fetch to resolve
            await vi.waitFor(() => {
                expect(mockedAxios.get).toHaveBeenCalled();
            });
        });

        it('setSearchQuery sets query and fetches', async () => {
            mockedAxios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.setSearchQuery('auth');

            expect(store.searchQuery).toBe('auth');
            await vi.waitFor(() => {
                expect(mockedAxios.get).toHaveBeenCalled();
            });
        });

        it('setShowArchived toggles and fetches', async () => {
            mockedAxios.get.mockResolvedValue(makeApiResponse([]));

            const store = useConversationsStore();
            store.setShowArchived(true);

            expect(store.showArchived).toBe(true);
            await vi.waitFor(() => {
                expect(mockedAxios.get).toHaveBeenCalled();
            });
        });
    });

    describe('createConversation', () => {
        it('creates conversation and adds to top of list', async () => {
            const newConv = makeConversation({ id: 'conv-new', title: 'New Chat' });
            mockedAxios.post.mockResolvedValue({ data: { data: newConv } });

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-old' })];

            const result = await store.createConversation(5);

            expect(mockedAxios.post).toHaveBeenCalledWith('/api/v1/conversations', {
                project_id: 5,
            });
            expect(store.conversations).toHaveLength(2);
            expect(store.conversations[0].id).toBe('conv-new');
            expect(store.conversations[1].id).toBe('conv-old');
            expect(result).toEqual(newConv);
        });

        it('selects the newly created conversation', async () => {
            const newConv = makeConversation({ id: 'conv-new' });
            mockedAxios.post.mockResolvedValue({ data: { data: newConv } });

            const store = useConversationsStore();
            await store.createConversation(1);

            expect(store.selectedId).toBe('conv-new');
        });

        it('sets error and throws on failure', async () => {
            mockedAxios.post.mockRejectedValue({
                response: { data: { message: 'Unauthorized' } },
            });

            const store = useConversationsStore();

            await expect(store.createConversation(1)).rejects.toBeTruthy();
            expect(store.error).toBe('Unauthorized');
        });

        it('uses fallback error message when no response message', async () => {
            mockedAxios.post.mockRejectedValue(new Error('Network error'));

            const store = useConversationsStore();

            await expect(store.createConversation(1)).rejects.toBeTruthy();
            expect(store.error).toBe('Failed to create conversation');
        });

        it('clears previous error on new create attempt', async () => {
            const store = useConversationsStore();
            store.error = 'Previous error';

            const newConv = makeConversation({ id: 'conv-new' });
            mockedAxios.post.mockResolvedValue({ data: { data: newConv } });

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
            mockedAxios.post.mockResolvedValue({ data: { data: updatedConv } });

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];

            const result = await store.addProjectToConversation('conv-1', 2);

            expect(mockedAxios.post).toHaveBeenCalledWith(
                '/api/v1/conversations/conv-1/projects',
                { project_id: 2 },
            );
            expect(store.conversations[0].additional_projects).toEqual([
                { id: 2, name: 'New Project' },
            ]);
            expect(result).toEqual(updatedConv);
        });

        it('sets error and throws on failure', async () => {
            mockedAxios.post.mockRejectedValue({
                response: { data: { message: 'Cross-project not allowed' } },
            });

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];

            await expect(
                store.addProjectToConversation('conv-1', 2),
            ).rejects.toBeTruthy();
            expect(store.error).toBe('Cross-project not allowed');
        });

        it('uses fallback error message when no response message', async () => {
            mockedAxios.post.mockRejectedValue(new Error('Network error'));

            const store = useConversationsStore();
            store.conversations = [makeConversation({ id: 'conv-1' })];

            await expect(
                store.addProjectToConversation('conv-1', 2),
            ).rejects.toBeTruthy();
            expect(store.error).toBe('Failed to add project');
        });

        it('does not modify list if conversation not found', async () => {
            const updatedConv = makeConversation({ id: 'conv-999' });
            mockedAxios.post.mockResolvedValue({ data: { data: updatedConv } });

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
            store.messages = [{ id: 'msg-1' }];
            store.messagesLoading = true;
            store.messagesError = 'error';
            store.sending = true;

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
            expect(store.messages).toEqual([]);
            expect(store.messagesLoading).toBe(false);
            expect(store.messagesError).toBeNull();
            expect(store.sending).toBe(false);
        });
    });

    describe('message actions', () => {
        it('fetchMessages loads messages for a conversation', async () => {
            const messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
                { id: 'msg-2', role: 'assistant', content: 'Hi!', created_at: '2026-02-15T12:00:01+00:00' },
            ];
            mockedAxios.get.mockResolvedValueOnce({
                data: { data: { id: 1, messages } },
            });

            const store = useConversationsStore();
            await store.fetchMessages(1);

            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/conversations/1');
            expect(store.messages).toEqual(messages);
        });

        it('fetchMessages sets messagesLoading while fetching', async () => {
            let resolve: (value: unknown) => void;
            mockedAxios.get.mockReturnValueOnce(new Promise((r) => {
                resolve = r;
            }) as never);

            const store = useConversationsStore();
            const promise = store.fetchMessages(1);

            expect(store.messagesLoading).toBe(true);

            resolve!({ data: { data: { id: 1, messages: [] } } });
            await promise;

            expect(store.messagesLoading).toBe(false);
        });

        it('fetchMessages sets messagesError on failure', async () => {
            mockedAxios.get.mockRejectedValueOnce({
                response: { data: { message: 'Not found' } },
            });

            const store = useConversationsStore();
            await store.fetchMessages(999);

            expect(store.messagesError).toBe('Not found');
            expect(store.messages).toEqual([]);
        });

        it('sendMessage posts and appends user message', async () => {
            const userMessage = {
                id: 'msg-new',
                role: 'user',
                content: 'Hello AI',
                created_at: '2026-02-15T12:05:00+00:00',
            };
            mockedAxios.post.mockResolvedValueOnce({
                data: { data: userMessage },
            });

            const store = useConversationsStore();
            store.selectedId = 1;
            store.messages = [];

            await store.sendMessage('Hello AI');

            expect(mockedAxios.post).toHaveBeenCalledWith('/api/v1/conversations/1/messages', {
                content: 'Hello AI',
            });
            expect(store.messages).toHaveLength(1);
            expect(store.messages[0].content).toBe('Hello AI');
        });

        it('sendMessage sets sending flag', async () => {
            let resolve: (value: unknown) => void;
            mockedAxios.post.mockReturnValueOnce(new Promise((r) => {
                resolve = r;
            }) as never);

            const store = useConversationsStore();
            store.selectedId = 1;
            const promise = store.sendMessage('Test');

            expect(store.sending).toBe(true);

            resolve!({ data: { data: { id: 'msg-1', role: 'user', content: 'Test', created_at: '2026-02-15T12:00:00+00:00' } } });
            await promise;

            expect(store.sending).toBe(false);
        });

        it('sendMessage sets error on failure', async () => {
            mockedAxios.post.mockRejectedValueOnce({
                response: { data: { message: 'Validation error' } },
            });

            const store = useConversationsStore();
            store.selectedId = 1;

            await store.sendMessage('Test');

            expect(store.messagesError).toBe('Validation error');
        });
    });

    function mockSSEFetch(events: (Record<string, unknown> | string)[]) {
        const lines = events.map(e =>
            typeof e === 'string' ? `data: ${e}\n\n` : `data: ${JSON.stringify(e)}\n\n`,
        ).join('');
        const encoder = new TextEncoder();
        const stream = new ReadableStream({
            start(controller) {
                controller.enqueue(encoder.encode(lines));
                controller.close();
            },
        });
        return Promise.resolve(new Response(stream, {
            status: 200,
            headers: { 'Content-Type': 'text/event-stream' },
        }));
    }

    function standardEvents(deltas: string[] = ['Hello', ' world']) {
        return [
            { type: 'stream_start' },
            { type: 'text_start' },
            ...deltas.map(d => ({ type: 'text_delta', delta: d })),
            { type: 'text_end' },
            { type: 'stream_end' },
            '[DONE]',
        ];
    }

    describe('streamMessage', () => {
        beforeEach(() => {
            // Set up CSRF meta tag for fetch using safe DOM methods
            const meta = document.createElement('meta');
            meta.setAttribute('name', 'csrf-token');
            meta.setAttribute('content', 'test-csrf-token');
            document.head.appendChild(meta);
        });

        afterEach(() => {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta)
                meta.remove();
            vi.unstubAllGlobals();
        });

        it('adds optimistic user message before streaming starts', async () => {
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(standardEvents())));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Hello AI');

            // First message should be the user's
            expect(store.messages[0].role).toBe('user');
            expect(store.messages[0].content).toBe('Hello AI');
        });

        it('accumulates text_delta events into assistant message', async () => {
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(standardEvents(['Hello', ' world', '!']))));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Tell me something');

            // Last message should be the accumulated assistant response
            const assistantMsg = store.messages.find((m: Record<string, unknown>) => m.role === 'assistant');
            expect(assistantMsg).toBeDefined();
            expect(assistantMsg!.content).toBe('Hello world!');
        });

        it('sets streaming flag during SSE connection', async () => {
            let resolveStream: (value: unknown) => void;
            vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => {
                resolveStream = resolve;
            })));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            const promise = store.streamMessage('Test');

            expect(store.streaming).toBe(true);

            // Resolve with a completed stream
            const lines = 'data: {"type":"stream_start"}\n\ndata: {"type":"stream_end"}\n\ndata: [DONE]\n\n';
            const encoder = new TextEncoder();
            const stream = new ReadableStream({
                start(controller) {
                    controller.enqueue(encoder.encode(lines));
                    controller.close();
                },
            });
            resolveStream!(new Response(stream, {
                status: 200,
                headers: { 'Content-Type': 'text/event-stream' },
            }));

            await promise;

            expect(store.streaming).toBe(false);
        });

        it('sets streamingContent reactively as deltas arrive', async () => {
            // Use chunked delivery to observe intermediate state
            let controller: ReadableStreamDefaultController<Uint8Array>;
            const stream = new ReadableStream<Uint8Array>({
                start(c) {
                    controller = c;
                },
            });
            const encoder = new TextEncoder();

            vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(
                new Response(stream, {
                    status: 200,
                    headers: { 'Content-Type': 'text/event-stream' },
                }),
            )));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            const promise = store.streamMessage('Test');

            // Wait for fetch to resolve and start reading
            await vi.waitFor(() => expect(store.streaming).toBe(true));

            controller!.enqueue(encoder.encode('data: {"type":"stream_start"}\n\ndata: {"type":"text_start"}\n\n'));
            controller!.enqueue(encoder.encode('data: {"type":"text_delta","delta":"Hi"}\n\n'));

            // Wait for the delta to be processed
            await vi.waitFor(() => expect(store.streamingContent).toBe('Hi'));

            controller!.enqueue(encoder.encode('data: {"type":"text_delta","delta":" there"}\n\n'));

            await vi.waitFor(() => expect(store.streamingContent).toBe('Hi there'));

            controller!.enqueue(encoder.encode('data: {"type":"text_end"}\n\ndata: {"type":"stream_end"}\n\ndata: [DONE]\n\n'));
            controller!.close();

            await promise;

            expect(store.streaming).toBe(false);
            expect(store.streamingContent).toBe('');
        });

        it('posts to the stream endpoint with CSRF token and credentials', async () => {
            const fetchMock = vi.fn(() => mockSSEFetch(standardEvents()));
            vi.stubGlobal('fetch', fetchMock);

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Test message');

            expect(fetchMock).toHaveBeenCalledWith(
                '/api/v1/conversations/conv-1/stream',
                expect.objectContaining({
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: expect.objectContaining({
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': 'test-csrf-token',
                        'X-Requested-With': 'XMLHttpRequest',
                    }),
                    body: JSON.stringify({ content: 'Test message' }),
                }),
            );
        });

        it('does nothing when no conversation is selected', async () => {
            const fetchMock = vi.fn();
            vi.stubGlobal('fetch', fetchMock);

            const store = useConversationsStore();
            store.selectedId = null;

            await store.streamMessage('Test');

            expect(fetchMock).not.toHaveBeenCalled();
        });

        it('sets messagesError on fetch failure', async () => {
            vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('Network error'))));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Test');

            expect(store.messagesError).toBeTruthy();
            expect(store.streaming).toBe(false);
        });

        it('sets messagesError on non-ok response', async () => {
            vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(
                new Response('Forbidden', { status: 403 }),
            )));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Test');

            expect(store.messagesError).toBeTruthy();
            expect(store.streaming).toBe(false);
        });

        it('finalizes assistant message with id and timestamp after stream completes', async () => {
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(standardEvents(['Response text']))));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Test');

            const assistantMsg = store.messages.find((m: Record<string, unknown>) => m.role === 'assistant');
            expect(assistantMsg!.content).toBe('Response text');
            // Should have an id (even if temporary) and created_at
            expect(assistantMsg!.id).toBeDefined();
            expect(assistantMsg!.created_at).toBeDefined();
        });

        it('tracks active tool calls from tool_call events', async () => {
            const events = [
                { type: 'stream_start' },
                { type: 'tool_call', tool_name: 'ReadFile', arguments: { file_path: 'src/Auth.php' } },
                { type: 'tool_result', tool_name: 'ReadFile', result: '<?php ...' },
                { type: 'text_start' },
                { type: 'text_delta', delta: 'Here is the file' },
                { type: 'text_end' },
                { type: 'stream_end' },
                '[DONE]',
            ];
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Show me Auth.php');

            // After stream completes, activeToolCalls should be cleared
            expect(store.activeToolCalls).toEqual([]);
        });

        it('adds tool_call to activeToolCalls during streaming', async () => {
            let controller: ReadableStreamDefaultController<Uint8Array>;
            const stream = new ReadableStream<Uint8Array>({
                start(c) {
                    controller = c;
                },
            });
            const encoder = new TextEncoder();

            vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(
                new Response(stream, {
                    status: 200,
                    headers: { 'Content-Type': 'text/event-stream' },
                }),
            )));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            const promise = store.streamMessage('Test');
            await vi.waitFor(() => expect(store.streaming).toBe(true));

            controller!.enqueue(encoder.encode('data: {"type":"stream_start"}\n\n'));
            controller!.enqueue(encoder.encode('data: {"type":"tool_call","tool_name":"BrowseRepoTree","arguments":{"project_id":1,"path":"src/"}}\n\n'));

            await vi.waitFor(() => expect(store.activeToolCalls).toHaveLength(1));
            expect(store.activeToolCalls[0].tool).toBe('BrowseRepoTree');
            expect(store.activeToolCalls[0].input.path).toBe('src/');

            controller!.enqueue(encoder.encode('data: {"type":"tool_result","tool_name":"BrowseRepoTree","result":"file1.php\\nfile2.php"}\n\n'));

            await vi.waitFor(() => expect(store.activeToolCalls).toHaveLength(0));

            controller!.enqueue(encoder.encode('data: {"type":"stream_end"}\n\ndata: [DONE]\n\n'));
            controller!.close();
            await promise;
        });

        it('clears activeToolCalls when stream completes', async () => {
            const events = [
                { type: 'stream_start' },
                { type: 'tool_call', tool_name: 'SearchCode', arguments: { query: 'processPayment' } },
                // Note: no tool_result — simulates edge case
                { type: 'stream_end' },
                '[DONE]',
            ];
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Test');

            expect(store.activeToolCalls).toEqual([]);
        });

        it('re-fetches messages on stream error for connection resilience', async () => {
            // First call: fetch fails mid-stream
            const failStream = new ReadableStream({
                start(controller) {
                    const encoder = new TextEncoder();
                    controller.enqueue(encoder.encode('data: {"type":"stream_start"}\n\n'));
                    controller.error(new Error('Connection lost'));
                },
            });
            vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(
                new Response(failStream, {
                    status: 200,
                    headers: { 'Content-Type': 'text/event-stream' },
                }),
            )));

            // Mock axios.get for the recovery re-fetch
            const recoveredMessages = [
                { id: 'msg-1', role: 'user', content: 'Test', created_at: '2026-02-15T12:00:00+00:00' },
                { id: 'msg-2', role: 'assistant', content: 'Full recovered response', created_at: '2026-02-15T12:00:01+00:00' },
            ];
            mockedAxios.get.mockResolvedValueOnce({
                data: { data: { id: 'conv-1', messages: recoveredMessages } },
            });

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Test');

            // Should have re-fetched messages from the API
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/conversations/conv-1');
            expect(store.messages).toEqual(recoveredMessages);
            expect(store.streaming).toBe(false);
        });

        it('detects action_preview block in streamed text and sets pendingAction', async () => {
            const previewJson = JSON.stringify({
                action_type: 'create_issue',
                project_id: 42,
                title: 'Add authentication',
                description: 'Implement OAuth login flow',
            });
            const events = [
                { type: 'stream_start' },
                { type: 'text_start' },
                { type: 'text_delta', delta: 'I\'ll create an Issue for this.\n\n```action_preview\n' },
                { type: 'text_delta', delta: previewJson },
                { type: 'text_delta', delta: '\n```\n\nPlease confirm.' },
                { type: 'text_end' },
                { type: 'stream_end' },
                '[DONE]',
            ];
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Create an auth issue');

            expect(store.pendingAction).not.toBeNull();
            expect(store.pendingAction.action_type).toBe('create_issue');
            expect(store.pendingAction.project_id).toBe(42);
            expect(store.pendingAction.title).toBe('Add authentication');
        });

        it('does not set pendingAction for malformed JSON in action_preview block', async () => {
            const events = [
                { type: 'stream_start' },
                { type: 'text_start' },
                { type: 'text_delta', delta: '```action_preview\n{invalid json}\n```' },
                { type: 'text_end' },
                { type: 'stream_end' },
                '[DONE]',
            ];
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Test');

            expect(store.pendingAction).toBeNull();
        });

        it('does not overwrite pendingAction if one is already set', async () => {
            const events = [
                { type: 'stream_start' },
                { type: 'text_start' },
                { type: 'text_delta', delta: '```action_preview\n{"action_type":"create_issue","project_id":1,"title":"First","description":"first"}\n```' },
                { type: 'text_delta', delta: ' ```action_preview\n{"action_type":"create_mr","project_id":2,"title":"Second","description":"second"}\n```' },
                { type: 'text_end' },
                { type: 'stream_end' },
                '[DONE]',
            ];
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Test');

            // First preview should be kept, second ignored
            expect(store.pendingAction.action_type).toBe('create_issue');
            expect(store.pendingAction.title).toBe('First');
        });

        it('restores pendingAction from last assistant message on fetchMessages', async () => {
            const previewJson = JSON.stringify({
                action_type: 'deep_analysis',
                project_id: 42,
                title: 'Deep scan',
                description: 'Full analysis',
            });
            const messagesFromApi = [
                { id: 'msg-1', role: 'user', content: 'Analyze this', created_at: '2026-02-15T12:00:00+00:00' },
                { id: 'msg-2', role: 'assistant', content: `I'll run an analysis.\n\n\`\`\`action_preview\n${previewJson}\n\`\`\`\n\nPlease confirm.`, created_at: '2026-02-15T12:00:01+00:00' },
            ];
            mockedAxios.get.mockResolvedValueOnce({
                data: { data: { id: 'conv-1', messages: messagesFromApi } },
            });

            const store = useConversationsStore();
            await store.fetchMessages('conv-1');

            // pendingAction should be restored from persisted message
            expect(store.pendingAction).not.toBeNull();
            expect(store.pendingAction.action_type).toBe('deep_analysis');
            expect(store.pendingAction.title).toBe('Deep scan');
        });

        it('does not restore pendingAction when last message is from user (already confirmed)', async () => {
            const previewJson = JSON.stringify({
                action_type: 'create_issue',
                project_id: 42,
                title: 'Test issue',
            });
            const messagesFromApi = [
                { id: 'msg-1', role: 'user', content: 'Create issue', created_at: '2026-02-15T12:00:00+00:00' },
                { id: 'msg-2', role: 'assistant', content: `\`\`\`action_preview\n${previewJson}\n\`\`\``, created_at: '2026-02-15T12:00:01+00:00' },
                { id: 'msg-3', role: 'user', content: 'Confirmed. Go ahead with: Test issue', created_at: '2026-02-15T12:00:02+00:00' },
            ];
            mockedAxios.get.mockResolvedValueOnce({
                data: { data: { id: 'conv-1', messages: messagesFromApi } },
            });

            const store = useConversationsStore();
            await store.fetchMessages('conv-1');

            // pendingAction should NOT be restored — user already confirmed
            expect(store.pendingAction).toBeNull();
        });

        it('auto-tracks task from [System: Task dispatched] in streamed response (T69)', async () => {
            const dispatchText = 'Alright, I\'ll implement that.\n\n[System: Task dispatched] Feature implementation "Add Stripe" has been dispatched as Task #42. You can track its progress in the pinned task bar.';
            const events = [
                { type: 'stream_start' },
                { type: 'text_start' },
                { type: 'text_delta', delta: dispatchText },
                { type: 'text_end' },
                { type: 'stream_end' },
                '[DONE]',
            ];
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Implement Stripe');

            // Task should be tracked
            expect(store.activeTasks.size).toBe(1);
            expect(store.activeTasks.get(42)).toBeDefined();
            expect(store.activeTasks.get(42).title).toBe('Add Stripe');
            expect(store.activeTasks.get(42).type).toBe('feature_dev');

            // Should have subscribed to the task's Reverb channel
            expect(mockEchoInstance.private).toHaveBeenCalledWith('task.42');
        });

        it('does not auto-track when no [System: Task dispatched] in response', async () => {
            const events = [
                { type: 'stream_start' },
                { type: 'text_start' },
                { type: 'text_delta', delta: 'Here is a regular response.' },
                { type: 'text_end' },
                { type: 'stream_end' },
                '[DONE]',
            ];
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Test');

            expect(store.activeTasks.size).toBe(0);
            expect(mockEchoInstance.private).not.toHaveBeenCalled();
        });

        // ─── D187/D188: Structured SSE error handling ───────────

        it('handles retryable stream error and sets streamRetryable', async () => {
            // Stream with error event (emitted by ResilientStreamResponse)
            const events = [
                { type: 'stream_start' },
                { type: 'text_delta', delta: 'partial response' },
                { type: 'error', error: { message: 'AI service busy', code: 'rate_limited', retryable: true } },
                '[DONE]',
            ];
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

            // Mock recovery re-fetch
            const recoveredMessages = [
                { id: 'msg-1', role: 'user', content: 'Test', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            mockedAxios.get.mockResolvedValueOnce({
                data: { data: { id: 'conv-1', messages: recoveredMessages } },
            });

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Test');

            expect(store.streamRetryable).toBe(true);
            expect(store.messagesError).toBe('AI service busy');
            expect(store.streaming).toBe(false);
            expect(store.streamingContent).toBe('');
            expect(store.activeToolCalls).toEqual([]);
            // Should re-fetch persisted messages
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/conversations/conv-1');
        });

        it('handles non-retryable stream error and does not set streamRetryable', async () => {
            const events = [
                { type: 'stream_start' },
                { type: 'error', error: { message: 'Generic AI error', code: 'ai_error', retryable: false } },
                '[DONE]',
            ];
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

            mockedAxios.get.mockResolvedValueOnce({
                data: { data: { id: 'conv-1', messages: [] } },
            });

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            await store.streamMessage('Test');

            expect(store.streamRetryable).toBe(false);
            expect(store.messagesError).toBe('Generic AI error');
            expect(store.streaming).toBe(false);
        });

        it('resets streamRetryable on new stream attempt', async () => {
            const events = standardEvents();
            vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];
            store.streamRetryable = true; // From previous error

            await store.streamMessage('Test');

            expect(store.streamRetryable).toBe(false);
            expect(store.messagesError).toBeNull();
        });
    });

    describe('active task tracking (T69)', () => {
        it('trackTask adds a task to activeTasks', () => {
            const store = useConversationsStore();
            store.trackTask({
                task_id: 42,
                status: 'queued',
                type: 'feature_dev',
                title: 'Implement payment',
                project_id: 1,
                pipeline_id: null,
                pipeline_status: null,
                started_at: null,
                conversation_id: 'conv-1',
            });

            expect(store.activeTasks.size).toBe(1);
            expect(store.activeTasks.get(42).title).toBe('Implement payment');
        });

        it('updateTaskStatus updates an existing tracked task', () => {
            const store = useConversationsStore();
            store.trackTask({
                task_id: 42,
                status: 'queued',
                type: 'feature_dev',
                title: 'Implement payment',
                project_id: 1,
                pipeline_id: null,
                pipeline_status: null,
                started_at: null,
                conversation_id: 'conv-1',
            });

            store.updateTaskStatus(42, {
                status: 'running',
                pipeline_id: 999,
                pipeline_status: 'running',
                started_at: '2026-02-15T12:00:00Z',
            });

            const task = store.activeTasks.get(42);
            expect(task.status).toBe('running');
            expect(task.pipeline_id).toBe(999);
            expect(task.started_at).toBe('2026-02-15T12:00:00Z');
        });

        it('updateTaskStatus is a no-op for untracked tasks', () => {
            const store = useConversationsStore();
            store.updateTaskStatus(99, { status: 'running' });
            expect(store.activeTasks.size).toBe(0);
        });

        it('removeTask removes a task from activeTasks', () => {
            const store = useConversationsStore();
            store.trackTask({
                task_id: 42,
                status: 'queued',
                type: 'feature_dev',
                title: 'Test',
                project_id: 1,
                pipeline_id: null,
                pipeline_status: null,
                started_at: null,
                conversation_id: 'conv-1',
            });

            store.removeTask(42);
            expect(store.activeTasks.size).toBe(0);
        });

        it('activeTasksForConversation returns tasks for the selected conversation', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.trackTask({
                task_id: 42,
                status: 'running',
                type: 'feature_dev',
                title: 'Task A',
                project_id: 1,
                pipeline_id: null,
                pipeline_status: null,
                started_at: null,
                conversation_id: 'conv-1',
            });
            store.trackTask({
                task_id: 43,
                status: 'running',
                type: 'code_review',
                title: 'Task B',
                project_id: 1,
                pipeline_id: null,
                pipeline_status: null,
                started_at: null,
                conversation_id: 'conv-2',
            });

            expect(store.activeTasksForConversation.length).toBe(1);
            expect(store.activeTasksForConversation[0].task_id).toBe(42);
        });

        it('activeTasksForConversation filters out terminal statuses', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.trackTask({
                task_id: 42,
                status: 'completed',
                type: 'feature_dev',
                title: 'Done Task',
                project_id: 1,
                pipeline_id: null,
                pipeline_status: null,
                started_at: null,
                conversation_id: 'conv-1',
            });

            expect(store.activeTasksForConversation.length).toBe(0);
        });

        it('$reset clears activeTasks', () => {
            const store = useConversationsStore();
            store.trackTask({
                task_id: 42,
                status: 'running',
                type: 'feature_dev',
                title: 'Test',
                project_id: 1,
                pipeline_id: null,
                pipeline_status: null,
                started_at: null,
                conversation_id: 'conv-1',
            });

            store.$reset();
            expect(store.activeTasks.size).toBe(0);
        });

        it('detects task dispatch from streamed system message', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';

            const systemMsg = '[System: Task dispatched] Feature implementation "Add Stripe" has been dispatched as Task #42. You can track its progress in the pinned task bar.';

            const parsed = store.parseTaskDispatchMessage(systemMsg);
            expect(parsed).toEqual({ taskId: 42, title: 'Add Stripe', typeLabel: 'Feature implementation' });
        });

        it('parseTaskDispatchMessage returns null for non-dispatch messages', () => {
            const store = useConversationsStore();
            expect(store.parseTaskDispatchMessage('Hello world')).toBeNull();
            expect(store.parseTaskDispatchMessage('[System: something else]')).toBeNull();
        });
    });

    describe('reverb channel subscription (T69)', () => {
        it('subscribeToTask subscribes to task.{id} channel', () => {
            const store = useConversationsStore();
            store.subscribeToTask(42);
            expect(mockEchoInstance.private).toHaveBeenCalledWith('task.42');
        });

        it('subscribeToTask listens for task.status.changed events', () => {
            const store = useConversationsStore();
            store.subscribeToTask(42);
            expect(mockListenFn).toHaveBeenCalledWith(
                '.task.status.changed',
                expect.any(Function),
            );
        });

        it('does not double-subscribe to the same task', () => {
            const store = useConversationsStore();
            store.subscribeToTask(42);
            store.subscribeToTask(42);
            expect(mockEchoInstance.private).toHaveBeenCalledTimes(1);
        });

        it('unsubscribeFromTask leaves the channel', () => {
            const store = useConversationsStore();
            store.unsubscribeFromTask(42);
            expect(mockEchoInstance.leave).toHaveBeenCalledWith('task.42');
        });

        it('receiving task.status.changed event updates tracked task', () => {
            const store = useConversationsStore();
            store.trackTask({
                task_id: 42,
                status: 'queued',
                type: 'feature_dev',
                title: 'Test',
                project_id: 1,
                pipeline_id: null,
                pipeline_status: null,
                started_at: null,
                conversation_id: 'conv-1',
            });

            store.subscribeToTask(42);

            // Simulate event by calling the listen callback
            const callback = mockListenFn.mock.calls[0][1];
            callback({
                task_id: 42,
                status: 'running',
                pipeline_id: 999,
                pipeline_status: 'running',
                started_at: '2026-02-15T12:00:00Z',
            });

            const task = store.activeTasks.get(42);
            expect(task.status).toBe('running');
            expect(task.pipeline_id).toBe(999);
        });

        it('schedules removal on terminal status event', () => {
            vi.useFakeTimers();
            const store = useConversationsStore();
            store.trackTask({
                task_id: 42,
                status: 'running',
                type: 'feature_dev',
                title: 'Test',
                project_id: 1,
                pipeline_id: 100,
                pipeline_status: 'running',
                started_at: '2026-02-15T12:00:00Z',
                conversation_id: 'conv-1',
            });

            store.subscribeToTask(42);

            const callback = mockListenFn.mock.calls[0][1];
            callback({
                task_id: 42,
                status: 'completed',
                pipeline_id: 100,
                pipeline_status: 'success',
                started_at: '2026-02-15T12:00:00Z',
            });

            // Task should still exist immediately after terminal event
            expect(store.activeTasks.has(42)).toBe(true);

            // After 3s delay, task should be removed
            vi.advanceTimersByTime(3000);
            expect(store.activeTasks.has(42)).toBe(false);
            expect(mockEchoInstance.leave).toHaveBeenCalledWith('task.42');

            vi.useRealTimers();
        });

        it('$reset clears task subscriptions', () => {
            const store = useConversationsStore();
            store.subscribeToTask(42);
            store.$reset();
            // After reset, subscribing again should work (not be deduped)
            store.subscribeToTask(42);
            expect(mockEchoInstance.private).toHaveBeenCalledTimes(2);
        });
    });

    describe('result card delivery (T70)', () => {
        it('adds completed result to completedResults when deliverTaskResult is called', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';

            store.trackTask({
                task_id: 42,
                status: 'running',
                type: 'feature_dev',
                title: 'Test task',
                conversation_id: 'conv-1',
                project_id: 1,
            });

            store.deliverTaskResult(42, {
                status: 'completed',
                type: 'feature_dev',
                title: 'Test task',
                mr_iid: 123,
                issue_iid: null,
                result_summary: 'Created MR',
                error_reason: null,
                result_data: {
                    branch: 'ai/test',
                    target_branch: 'main',
                    files_changed: [{ path: 'foo.php', action: 'created', summary: 'New file' }],
                },
                conversation_id: 'conv-1',
                project_id: 1,
            });

            expect(store.completedResults.length).toBe(1);
            expect(store.completedResults[0].task_id).toBe(42);
            expect(store.completedResults[0].status).toBe('completed');
        });

        it('appends system context marker message on result delivery', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            store.deliverTaskResult(42, {
                status: 'completed',
                type: 'feature_dev',
                title: 'Test task',
                mr_iid: 123,
                issue_iid: null,
                result_summary: 'Done',
                error_reason: null,
                result_data: {},
                conversation_id: 'conv-1',
                project_id: 1,
            });

            expect(store.messages.length).toBe(1);
            expect(store.messages[0].content).toContain('[System: Task result delivered]');
            expect(store.messages[0].role).toBe('system');
        });

        it('completedResultsForConversation only returns results for selected conversation', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';

            store.completedResults.push(
                { task_id: 1, conversation_id: 'conv-1', status: 'completed' },
                { task_id: 2, conversation_id: 'conv-2', status: 'completed' },
            );

            expect(store.completedResultsForConversation.length).toBe(1);
            expect(store.completedResultsForConversation[0].task_id).toBe(1);
        });

        it('delivers result card on terminal Reverb event via subscribeToTask', () => {
            vi.useFakeTimers();
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [];

            store.trackTask({
                task_id: 42,
                status: 'running',
                type: 'feature_dev',
                title: 'Payment feature',
                pipeline_id: 100,
                pipeline_status: 'running',
                started_at: '2026-02-15T12:00:00Z',
                project_id: 1,
                conversation_id: 'conv-1',
            });

            store.subscribeToTask(42);

            // Simulate terminal event
            const callback = mockListenFn.mock.calls[0][1];
            callback({
                task_id: 42,
                status: 'completed',
                type: 'feature_dev',
                title: 'Payment feature',
                mr_iid: 123,
                issue_iid: null,
                result_summary: 'Created MR',
                error_reason: null,
                result_data: { branch: 'ai/payment', target_branch: 'main', files_changed: [] },
                conversation_id: 'conv-1',
                project_id: 1,
                pipeline_id: 100,
                pipeline_status: 'success',
                started_at: '2026-02-15T12:00:00Z',
            });

            expect(store.completedResults.length).toBe(1);
            expect(store.completedResults[0].task_id).toBe(42);
            expect(store.messages.some((m: Record<string, unknown>) => (m.content as string).includes('[System: Task result delivered]'))).toBe(true);

            vi.useRealTimers();
        });

        it('hydrates result cards from system messages after fetchMessages', async () => {
            mockedAxios.get
                .mockResolvedValueOnce({
                    data: {
                        data: {
                            id: 'conv-1',
                            messages: [
                                { id: 'msg-1', role: 'user', content: 'Do it', created_at: '2026-02-15T12:00:00Z' },
                                { id: 'msg-2', role: 'system', content: '[System: Task result delivered] Task #42 "Add payment" completed.', created_at: '2026-02-15T12:01:00Z' },
                            ],
                        },
                    },
                })
                .mockResolvedValueOnce({
                    data: {
                        data: {
                            task_id: 42,
                            status: 'completed',
                            type: 'feature_dev',
                            title: 'Add payment',
                            mr_iid: 123,
                            issue_iid: null,
                            project_id: 1,
                            error_reason: null,
                            result: {
                                branch: 'ai/payment',
                                files_changed: [{ path: 'foo.php', action: 'created', summary: 'New' }],
                                notes: 'Done',
                            },
                        },
                    },
                });

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            await store.fetchMessages('conv-1');

            expect(store.completedResults.length).toBe(1);
            expect(store.completedResults[0].task_id).toBe(42);
            expect(store.completedResults[0].result_data.branch).toBe('ai/payment');
        });

        it('does not re-hydrate already-existing result cards', async () => {
            mockedAxios.get
                .mockResolvedValueOnce({
                    data: {
                        data: {
                            id: 'conv-1',
                            messages: [
                                { id: 'msg-2', role: 'system', content: '[System: Task result delivered] Task #42 "Add payment" completed.', created_at: '2026-02-15T12:01:00Z' },
                            ],
                        },
                    },
                });

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            // Pre-populate completedResults
            store.completedResults.push({ task_id: 42, conversation_id: 'conv-1', status: 'completed' });

            const callsBefore = mockedAxios.get.mock.calls.length;
            await store.fetchMessages('conv-1');

            // Should only have made one additional call (conversation fetch), not a task view fetch
            expect(mockedAxios.get.mock.calls.length - callsBefore).toBe(1);
            expect(store.completedResults.length).toBe(1);
        });

        it('silently skips hydration when task API returns error', async () => {
            mockedAxios.get
                .mockResolvedValueOnce({
                    data: {
                        data: {
                            id: 'conv-1',
                            messages: [
                                { id: 'msg-2', role: 'system', content: '[System: Task result delivered] Task #99 "Deleted task" completed.', created_at: '2026-02-15T12:01:00Z' },
                            ],
                        },
                    },
                })
                .mockRejectedValueOnce(new Error('Not found'));

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            await store.fetchMessages('conv-1');

            expect(store.completedResults.length).toBe(0);
        });

        it('$reset clears completedResults', () => {
            const store = useConversationsStore();
            store.completedResults.push({ task_id: 1, conversation_id: 'conv-1', status: 'completed' });

            store.$reset();
            expect(store.completedResults).toEqual([]);
        });
    });

    describe('action preview state (T68)', () => {
        it('initializes pendingAction as null', () => {
            const store = useConversationsStore();
            expect(store.pendingAction).toBeNull();
        });

        it('clears pendingAction on confirmAction', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.pendingAction = { id: 'p-1', action_type: 'create_issue', title: 'Test', description: 'Test' };

            // Mock streamMessage to prevent actual streaming
            vi.spyOn(store, 'streamMessage').mockResolvedValue();

            store.confirmAction();
            expect(store.pendingAction).toBeNull();
        });

        it('clears pendingAction on cancelAction', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.pendingAction = { id: 'p-1', action_type: 'create_issue', title: 'Test', description: 'Test' };

            // Mock streamMessage to prevent actual streaming
            vi.spyOn(store, 'streamMessage').mockResolvedValue();

            store.cancelAction();
            expect(store.pendingAction).toBeNull();
        });

        it('confirmAction sends confirmation message via fetch', async () => {
            const fetchMock = vi.fn(() => mockSSEFetch(standardEvents(['OK'])));
            vi.stubGlobal('fetch', fetchMock);

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.pendingAction = {
                id: 'p-1',
                action_type: 'implement_feature',
                title: 'Payment Feature',
                description: 'Implement Stripe',
            };

            await store.confirmAction();

            expect(fetchMock).toHaveBeenCalled();
            const body = JSON.parse(fetchMock.mock.calls[0][1].body);
            expect(body.content).toBe('Confirmed. Go ahead with: Payment Feature');
        });

        it('cancelAction sends cancellation message via fetch', async () => {
            const fetchMock = vi.fn(() => mockSSEFetch(standardEvents(['OK'])));
            vi.stubGlobal('fetch', fetchMock);

            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.pendingAction = {
                id: 'p-1',
                action_type: 'create_issue',
                title: 'Test Issue',
                description: 'Test',
            };

            // cancelAction fires streamMessage but doesn't await it
            store.cancelAction();
            await flushPromises();

            expect(fetchMock).toHaveBeenCalled();
            const body = JSON.parse(fetchMock.mock.calls[0][1].body);
            expect(body.content).toBe('Cancel this action, I changed my mind.');
        });

        it('confirmAction does nothing when no pendingAction', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.pendingAction = null;

            const streamSpy = vi.spyOn(store, 'streamMessage').mockResolvedValue();

            store.confirmAction();

            expect(streamSpy).not.toHaveBeenCalled();
        });

        it('cancelAction does nothing when no pendingAction', () => {
            const store = useConversationsStore();
            store.pendingAction = null;

            const streamSpy = vi.spyOn(store, 'streamMessage').mockResolvedValue();

            store.cancelAction();

            expect(streamSpy).not.toHaveBeenCalled();
        });

        it('clears pendingAction on $reset', () => {
            const store = useConversationsStore();
            store.pendingAction = { id: 'p-1', action_type: 'create_issue', title: 'Test', description: 'Test' };

            store.$reset();

            expect(store.pendingAction).toBeNull();
        });
    });
});
