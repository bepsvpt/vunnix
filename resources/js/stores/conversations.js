import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';
import { streamSSE } from '@/lib/sse';
import { getEcho } from '@/composables/useEcho';

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
    const activeToolCalls = ref([]);

    // Action preview state (T68)
    const pendingAction = ref(null);

    // Active task tracking (T69 — pinned task bar)
    const activeTasks = ref(new Map());

    const selected = computed(() =>
        conversations.value.find((c) => c.id === selectedId.value) || null
    );

    // T69: Active tasks for the currently selected conversation (excludes terminal statuses)
    const activeTasksForConversation = computed(() => {
        if (!selectedId.value) return [];
        return [...activeTasks.value.values()].filter(
            (t) => t.conversation_id === selectedId.value && !isTerminalStatus(t.status)
        );
    });

    function isTerminalStatus(status) {
        return ['completed', 'failed', 'superseded'].includes(status);
    }

    function trackTask(taskData) {
        const updated = new Map(activeTasks.value);
        updated.set(taskData.task_id, { ...taskData });
        activeTasks.value = updated;
    }

    function updateTaskStatus(taskId, statusData) {
        if (!activeTasks.value.has(taskId)) return;
        const updated = new Map(activeTasks.value);
        updated.set(taskId, { ...updated.get(taskId), ...statusData });
        activeTasks.value = updated;
    }

    function removeTask(taskId) {
        const updated = new Map(activeTasks.value);
        updated.delete(taskId);
        activeTasks.value = updated;
    }

    /**
     * Parse a [System: Task dispatched] message to extract task tracking info.
     * Returns { taskId, title, typeLabel } or null if not a dispatch message.
     */
    function parseTaskDispatchMessage(text) {
        const match = text.match(
            /\[System: Task dispatched\] (.+?) "(.+?)" has been dispatched as Task #(\d+)/
        );
        if (!match) return null;
        return {
            typeLabel: match[1],
            title: match[2],
            taskId: parseInt(match[3], 10),
        };
    }

    // Track active channel subscriptions (taskId → true)
    const taskSubscriptions = ref(new Set());

    /**
     * Map human-readable type labels from [System: Task dispatched] messages to internal type keys.
     */
    function typeFromLabel(label) {
        const map = {
            'Feature implementation': 'feature_dev',
            'UI adjustment': 'ui_adjustment',
            'Issue creation': 'prd_creation',
            'Merge request creation': 'feature_dev',
            'Deep analysis': 'deep_analysis',
        };
        return map[label] || 'feature_dev';
    }

    /**
     * Subscribe to a task's Reverb channel for real-time status updates.
     * Listens on private channel `task.{id}` for `.task.status.changed` events.
     */
    function subscribeToTask(taskId) {
        if (taskSubscriptions.value.has(taskId)) return;

        const echo = getEcho();
        echo.private(`task.${taskId}`).listen('.task.status.changed', (event) => {
            updateTaskStatus(event.task_id, {
                status: event.status,
                pipeline_id: event.pipeline_id,
                pipeline_status: event.pipeline_status,
                started_at: event.started_at,
                result_summary: event.result_summary,
            });

            // If terminal, schedule removal after brief delay
            if (['completed', 'failed', 'superseded'].includes(event.status)) {
                setTimeout(() => {
                    removeTask(event.task_id);
                    unsubscribeFromTask(event.task_id);
                }, 3000);
            }
        });

        taskSubscriptions.value = new Set([...taskSubscriptions.value, taskId]);
    }

    /**
     * Unsubscribe from a task's Reverb channel.
     */
    function unsubscribeFromTask(taskId) {
        const echo = getEcho();
        echo.leave(`task.${taskId}`);
        const updated = new Set(taskSubscriptions.value);
        updated.delete(taskId);
        taskSubscriptions.value = updated;
    }

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
        activeToolCalls.value = [];
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

                        // T68: Detect action_preview code blocks in streamed text
                        if (!pendingAction.value) {
                            const match = accumulated.match(/```action_preview\n([\s\S]*?)```/);
                            if (match) {
                                try {
                                    pendingAction.value = {
                                        id: `preview-${Date.now()}`,
                                        ...JSON.parse(match[1].trim()),
                                    };
                                } catch {
                                    // Malformed JSON — user will see raw text
                                }
                            }
                        }
                    }
                    if (event.type === 'tool_call') {
                        activeToolCalls.value.push({
                            id: event.id || `tool-${Date.now()}`,
                            tool: event.tool,
                            input: event.input || {},
                        });
                    }
                    if (event.type === 'tool_result') {
                        activeToolCalls.value = activeToolCalls.value.filter(
                            (tc) => tc.tool !== event.tool
                        );
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
                    activeToolCalls.value = [];

                    // T69: Auto-track dispatched tasks from system messages
                    const dispatch = parseTaskDispatchMessage(accumulated);
                    if (dispatch) {
                        trackTask({
                            task_id: dispatch.taskId,
                            status: 'received',
                            type: typeFromLabel(dispatch.typeLabel),
                            title: dispatch.title,
                            project_id: selected.value?.project_id || null,
                            pipeline_id: null,
                            pipeline_status: null,
                            started_at: null,
                            conversation_id: selectedId.value,
                        });
                        subscribeToTask(dispatch.taskId);
                    }
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
     * T68: Confirm a pending action preview.
     * Sends a confirmation message to the AI, which will then call DispatchAction.
     */
    async function confirmAction() {
        if (!pendingAction.value || !selectedId.value) return;
        const action = pendingAction.value;
        pendingAction.value = null;
        await streamMessage(`Confirmed. Go ahead with: ${action.title}`);
    }

    /**
     * T68: Cancel a pending action preview.
     * Sends a cancellation message to the AI.
     */
    function cancelAction() {
        if (!pendingAction.value) return;
        pendingAction.value = null;
        streamMessage('Cancel this action, I changed my mind.');
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
        activeToolCalls.value = [];
        pendingAction.value = null;
        activeTasks.value = new Map();
        taskSubscriptions.value = new Set();
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
        activeToolCalls,
        pendingAction,
        activeTasks,
        activeTasksForConversation,
        trackTask,
        updateTaskStatus,
        removeTask,
        parseTaskDispatchMessage,
        subscribeToTask,
        unsubscribeFromTask,
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
        confirmAction,
        cancelAction,
        createConversation,
        addProjectToConversation,
        $reset,
    };
});
