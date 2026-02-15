import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';
import { streamSSE } from '@/lib/sse';

export const useConversationsStore = defineStore('conversations', () => {
    const conversations = ref([]);
    const loading = ref(false);
    const error = ref(null);
    const nextCursor = ref(null);
    const hasMore = ref(false);

    // Filter state
    const projectFilter = ref(null);
    const searchQuery = ref('');
    const showArchived = ref(false);

    // Selected conversation ID (for highlighting active item)
    const selectedId = ref(null);

    // Message state (for selected conversation)
    const messages = ref([]);
    const messagesLoading = ref(false);
    const messagesError = ref(null);
    const sending = ref(false);

    // Streaming state
    const streaming = ref(false);
    const streamingContent = ref('');

    const selected = computed(() =>
        conversations.value.find((c) => c.id === selectedId.value) || null
    );

    /**
     * Fetch conversations from the API. Resets the list (page 1).
     */
    async function fetchConversations() {
        loading.value = true;
        error.value = null;
        try {
            const params = { per_page: 25 };
            if (projectFilter.value) params.project_id = projectFilter.value;
            if (searchQuery.value) params.search = searchQuery.value;
            if (showArchived.value) params.archived = 1;

            const response = await axios.get('/api/v1/conversations', { params });
            conversations.value = response.data.data;
            nextCursor.value = response.data.meta?.next_cursor || null;
            hasMore.value = !!nextCursor.value;
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to load conversations';
            conversations.value = [];
        } finally {
            loading.value = false;
        }
    }

    /**
     * Load the next page of conversations (append to list).
     */
    async function loadMore() {
        if (!nextCursor.value || loading.value) return;
        loading.value = true;
        try {
            const params = { cursor: nextCursor.value, per_page: 25 };
            if (projectFilter.value) params.project_id = projectFilter.value;
            if (searchQuery.value) params.search = searchQuery.value;
            if (showArchived.value) params.archived = 1;

            const response = await axios.get('/api/v1/conversations', { params });
            conversations.value.push(...response.data.data);
            nextCursor.value = response.data.meta?.next_cursor || null;
            hasMore.value = !!nextCursor.value;
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to load more';
        } finally {
            loading.value = false;
        }
    }

    /**
     * Toggle archive on a conversation. Removes it from the current list.
     */
    async function toggleArchive(conversationId) {
        try {
            await axios.patch(`/api/v1/conversations/${conversationId}/archive`);
            conversations.value = conversations.value.filter((c) => c.id !== conversationId);
            if (selectedId.value === conversationId) {
                selectedId.value = null;
            }
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to archive';
        }
    }

    function setProjectFilter(projectId) {
        projectFilter.value = projectId;
        fetchConversations();
    }

    function setSearchQuery(query) {
        searchQuery.value = query;
        fetchConversations();
    }

    function setShowArchived(archived) {
        showArchived.value = archived;
        fetchConversations();
    }

    /**
     * Fetch messages for a conversation by loading its detail.
     */
    async function fetchMessages(conversationId) {
        messagesLoading.value = true;
        messagesError.value = null;
        try {
            const response = await axios.get(`/api/v1/conversations/${conversationId}`);
            messages.value = response.data.data.messages || [];
        } catch (err) {
            messagesError.value = err.response?.data?.message || 'Failed to load messages';
            messages.value = [];
        } finally {
            messagesLoading.value = false;
        }
    }

    /**
     * Send a user message to the selected conversation.
     */
    async function sendMessage(content) {
        if (!selectedId.value) return;
        sending.value = true;
        messagesError.value = null;
        try {
            const response = await axios.post(
                `/api/v1/conversations/${selectedId.value}/messages`,
                { content }
            );
            messages.value.push(response.data.data);
        } catch (err) {
            messagesError.value = err.response?.data?.message || 'Failed to send message';
        } finally {
            sending.value = false;
        }
    }

    /**
     * Send a user message and stream the AI response via SSE.
     * Adds the user message optimistically, then streams the assistant response
     * token-by-token. On stream error, re-fetches messages from the API for
     * connection resilience (the SDK persists the complete response server-side).
     */
    async function streamMessage(content) {
        if (!selectedId.value) return;
        streaming.value = true;
        streamingContent.value = '';
        messagesError.value = null;

        // Optimistic user message
        const userMsg = {
            id: `temp-user-${Date.now()}`,
            role: 'user',
            content,
            created_at: new Date().toISOString(),
        };
        messages.value.push(userMsg);

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        try {
            const response = await fetch(
                `/api/v1/conversations/${selectedId.value}/stream`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'text/event-stream',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                    },
                    body: JSON.stringify({ content }),
                }
            );

            // Check response status before streaming
            if (!response.ok) {
                messagesError.value = `Request failed with status ${response.status}`;
                streaming.value = false;
                return;
            }

            let accumulated = '';

            await streamSSE(response, {
                onEvent(event) {
                    if (event.type === 'text_delta' && event.delta) {
                        accumulated += event.delta;
                        streamingContent.value = accumulated;
                    }
                },
                onDone() {
                    // Finalize the assistant message
                    const assistantMsg = {
                        id: `msg-${Date.now()}`,
                        role: 'assistant',
                        content: accumulated,
                        created_at: new Date().toISOString(),
                    };
                    messages.value.push(assistantMsg);
                    streamingContent.value = '';
                },
                async onError(err) {
                    messagesError.value = err.message || 'Stream interrupted';
                    // Connection resilience: re-fetch messages from REST API
                    // The SDK's RemembersConversations stores the complete response
                    await fetchMessages(selectedId.value);
                },
            });

            streaming.value = false;
        } catch (err) {
            messagesError.value = err.message || 'Failed to stream response';
            streaming.value = false;
        }
    }

    async function selectConversation(id) {
        selectedId.value = id;
        if (id) {
            await fetchMessages(id);
        } else {
            messages.value = [];
            messagesError.value = null;
        }
    }

    /**
     * Create a new conversation with a primary project.
     */
    async function createConversation(projectId) {
        error.value = null;
        try {
            const response = await axios.post('/api/v1/conversations', {
                project_id: projectId,
            });
            const newConversation = response.data.data;
            conversations.value.unshift(newConversation);
            selectedId.value = newConversation.id;
            return newConversation;
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to create conversation';
            throw err;
        }
    }

    /**
     * Add a project to an existing conversation (cross-project D28).
     */
    async function addProjectToConversation(conversationId, projectId) {
        error.value = null;
        try {
            const response = await axios.post(
                `/api/v1/conversations/${conversationId}/projects`,
                { project_id: projectId }
            );
            // Update conversation in list with new data
            const idx = conversations.value.findIndex((c) => c.id === conversationId);
            if (idx !== -1) {
                conversations.value[idx] = { ...conversations.value[idx], ...response.data.data };
            }
            return response.data.data;
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to add project';
            throw err;
        }
    }

    function $reset() {
        conversations.value = [];
        loading.value = false;
        error.value = null;
        nextCursor.value = null;
        hasMore.value = false;
        projectFilter.value = null;
        searchQuery.value = '';
        showArchived.value = false;
        selectedId.value = null;
        messages.value = [];
        messagesLoading.value = false;
        messagesError.value = null;
        sending.value = false;
        streaming.value = false;
        streamingContent.value = '';
    }

    return {
        conversations,
        loading,
        error,
        nextCursor,
        hasMore,
        projectFilter,
        searchQuery,
        showArchived,
        selectedId,
        selected,
        messages,
        messagesLoading,
        messagesError,
        sending,
        streaming,
        streamingContent,
        fetchConversations,
        loadMore,
        toggleArchive,
        setProjectFilter,
        setSearchQuery,
        setShowArchived,
        selectConversation,
        fetchMessages,
        sendMessage,
        streamMessage,
        createConversation,
        addProjectToConversation,
        $reset,
    };
});
