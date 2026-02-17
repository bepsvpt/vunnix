import type { StreamError } from '@/lib/sse';
import type { Conversation } from '@/types';
import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { getEcho } from '@/composables/useEcho';
import { streamSSE } from '@/lib/sse';

// --- Local interfaces ---

interface ChatMessage {
    id: string;
    role: string;
    content: string;
    created_at: string;
    [key: string]: unknown;
}

interface ToolCall {
    id: string;
    tool: string;
    input: Record<string, unknown>;
}

interface ActionPreview {
    id: string;
    title: string;
    [key: string]: unknown;
}

interface TrackedTask {
    task_id: number;
    status: string;
    type: string;
    title: string;
    project_id: number | null;
    pipeline_id: number | null;
    pipeline_status: string | null;
    started_at: string | null;
    conversation_id: string | null;
    result_summary?: string | null;
}

interface CompletedResult {
    task_id: number;
    status: string;
    type: string;
    title: string;
    mr_iid: number | null;
    issue_iid: number | null;
    result_summary: string | null;
    error_reason: string | null;
    result_data: Record<string, unknown>;
    conversation_id: string | null;
    project_id: number;
    gitlab_url: string;
}

interface SSEEvent {
    type: string;
    delta?: string;
    id?: string;
    // Laravel AI SDK sends tool_name and arguments (not tool/input)
    tool_name?: string;
    arguments?: Record<string, unknown>;
    // tool_result events include the tool's return value
    result?: string;
}

interface TaskDispatchInfo {
    typeLabel: string;
    title: string;
    taskId: number;
}

function getErrorMessage(err: unknown, fallback: string): string {
    const typed = err as { response?: { data?: { message?: string } } };
    return typed?.response?.data?.message || fallback;
}

/** Regex to detect action_preview fenced blocks in message text. */
const ACTION_PREVIEW_REGEX = /```action_preview\n([\s\S]*?)```/;

export const useConversationsStore = defineStore('conversations', () => {
    const conversations = ref<Conversation[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const nextCursor = ref<string | null>(null);
    const hasMore = ref(false);

    // Filter state
    const projectFilter = ref<number | null>(null);
    const searchQuery = ref('');
    const showArchived = ref(false);

    // Selected conversation ID (for highlighting active item)
    const selectedId = ref<string | null>(null);

    // Message state (for selected conversation)
    const messages = ref<ChatMessage[]>([]);
    const messagesLoading = ref(false);
    const messagesError = ref<string | null>(null);
    const sending = ref(false);

    // Streaming state
    const streaming = ref(false);
    const streamingContent = ref('');
    const activeToolCalls = ref<ToolCall[]>([]);
    const streamRetryable = ref(false);

    // Action preview state (T68)
    const pendingAction = ref<ActionPreview | null>(null);

    // Active task tracking (T69 — pinned task bar)
    const activeTasks = ref<Map<number, TrackedTask>>(new Map());

    // Completed task results for result cards (T70)
    const completedResults = ref<CompletedResult[]>([]);

    const selected = computed(() =>
        conversations.value.find(c => c.id === selectedId.value) || null,
    );

    // T69: Active tasks for the currently selected conversation (excludes terminal statuses)
    const activeTasksForConversation = computed(() => {
        if (!selectedId.value)
            return [];
        return [...activeTasks.value.values()].filter(
            t => t.conversation_id === selectedId.value && !isTerminalStatus(t.status),
        );
    });

    // T70: Completed results for the currently selected conversation
    const completedResultsForConversation = computed(() => {
        if (!selectedId.value)
            return [];
        return completedResults.value.filter(r => r.conversation_id === selectedId.value);
    });

    function isTerminalStatus(status: string): boolean {
        return ['completed', 'failed', 'superseded'].includes(status);
    }

    function trackTask(taskData: TrackedTask): void {
        const updated = new Map(activeTasks.value);
        updated.set(taskData.task_id, { ...taskData });
        activeTasks.value = updated;
    }

    function updateTaskStatus(taskId: number, statusData: Partial<TrackedTask>): void {
        if (!activeTasks.value.has(taskId))
            return;
        const updated = new Map(activeTasks.value);
        updated.set(taskId, { ...updated.get(taskId)!, ...statusData });
        activeTasks.value = updated;
    }

    function removeTask(taskId: number): void {
        const updated = new Map(activeTasks.value);
        updated.delete(taskId);
        activeTasks.value = updated;
    }

    /**
     * T70: Deliver a completed/failed task result for rendering as a ResultCard.
     * Called on terminal Reverb events. Adds to completedResults and appends
     * a system context marker message so the AI sees the status change.
     */
    function deliverTaskResult(taskId: number, eventData: Record<string, unknown>): void {
        completedResults.value.push({
            task_id: taskId,
            status: eventData.status as string,
            type: eventData.type as string,
            title: eventData.title as string,
            mr_iid: (eventData.mr_iid as number | null) ?? null,
            issue_iid: (eventData.issue_iid as number | null) ?? null,
            result_summary: (eventData.result_summary as string | null) ?? null,
            error_reason: (eventData.error_reason as string | null) ?? null,
            result_data: (eventData.result_data as Record<string, unknown>) || {},
            conversation_id: (eventData.conversation_id as string | null) ?? null,
            project_id: eventData.project_id as number,
            gitlab_url: '',
        });

        // Append system context marker message
        messages.value.push({
            id: `system-result-${taskId}-${Date.now()}`,
            role: 'system',
            content: `[System: Task result delivered] Task #${taskId} "${eventData.title}" ${eventData.status}.`,
            created_at: new Date().toISOString(),
        });
    }

    /**
     * Parse a [System: Task dispatched] message to extract task tracking info.
     * Returns { taskId, title, typeLabel } or null if not a dispatch message.
     */
    function parseTaskDispatchMessage(text: string): TaskDispatchInfo | null {
        const match = text.match(
            /\[System: Task dispatched\] (.+?) "(.+?)" has been dispatched as Task #(\d+)/,
        );
        if (!match)
            return null;
        return {
            typeLabel: match[1],
            title: match[2],
            taskId: Number.parseInt(match[3], 10),
        };
    }

    // Track active channel subscriptions (taskId → true)
    const taskSubscriptions = ref<Set<number>>(new Set());

    /**
     * Map human-readable type labels from [System: Task dispatched] messages to internal type keys.
     */
    function typeFromLabel(label: string): string {
        const map: Record<string, string> = {
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
    function subscribeToTask(taskId: number): void {
        if (taskSubscriptions.value.has(taskId))
            return;

        const echo = getEcho();
        echo.private(`task.${taskId}`).listen('.task.status.changed', (event: Record<string, unknown>) => {
            updateTaskStatus(event.task_id as number, {
                status: event.status as string,
                pipeline_id: event.pipeline_id as number | null,
                pipeline_status: event.pipeline_status as string | null,
                started_at: event.started_at as string | null,
                result_summary: event.result_summary as string | null,
            });

            // If terminal, deliver result card and schedule cleanup
            if (['completed', 'failed', 'superseded'].includes(event.status as string)) {
                deliverTaskResult(event.task_id as number, event);
                setTimeout(() => {
                    removeTask(event.task_id as number);
                    unsubscribeFromTask(event.task_id as number);
                }, 3000);
            }
        });

        taskSubscriptions.value = new Set([...taskSubscriptions.value, taskId]);

        // Catch up on status changes that happened before the subscription
        // was established (race condition: Queued/Running broadcasts may fire
        // before the frontend subscribes to the Reverb channel).
        pollCurrentTaskStatus(taskId);
    }

    async function pollCurrentTaskStatus(taskId: number): Promise<void> {
        try {
            const response = await axios.get(`/api/v1/tasks/${taskId}/view`);
            const data = response.data?.data;
            if (!data)
                return;

            const currentStatus = data.status as string;
            const tracked = activeTasks.value.get(taskId);

            // Only update if the polled status is newer than what we have
            if (tracked && currentStatus !== tracked.status) {
                updateTaskStatus(taskId, {
                    status: currentStatus,
                    pipeline_id: data.pipeline_id ?? null,
                    pipeline_status: data.pipeline_status ?? null,
                    started_at: data.started_at ?? null,
                    result_summary: data.result?.summary ?? data.result?.notes ?? null,
                });

                // Handle terminal status caught by polling
                if (isTerminalStatus(currentStatus)) {
                    deliverTaskResult(taskId, data);
                    setTimeout(() => {
                        removeTask(taskId);
                        unsubscribeFromTask(taskId);
                    }, 3000);
                }
            }
        } catch {
            // Non-critical — Reverb subscription is the primary mechanism
        }
    }

    /**
     * Unsubscribe from a task's Reverb channel.
     */
    function unsubscribeFromTask(taskId: number): void {
        const echo = getEcho();
        echo.leave(`task.${taskId}`);
        const updated = new Set(taskSubscriptions.value);
        updated.delete(taskId);
        taskSubscriptions.value = updated;
    }

    /**
     * Fetch conversations from the API. Resets the list (page 1).
     */
    async function fetchConversations(): Promise<void> {
        loading.value = true;
        error.value = null;
        try {
            const params: Record<string, string | number> = { per_page: 25 };
            if (projectFilter.value)
                params.project_id = projectFilter.value;
            if (searchQuery.value)
                params.search = searchQuery.value;
            if (showArchived.value)
                params.archived = 1;

            const response = await axios.get('/api/v1/conversations', { params });
            conversations.value = response.data.data;
            nextCursor.value = response.data.meta?.next_cursor || null;
            hasMore.value = !!nextCursor.value;
        } catch (err: unknown) {
            error.value = getErrorMessage(err, 'Failed to load conversations');
            conversations.value = [];
        } finally {
            loading.value = false;
        }
    }

    /**
     * Load the next page of conversations (append to list).
     */
    async function loadMore(): Promise<void> {
        if (!nextCursor.value || loading.value)
            return;
        loading.value = true;
        try {
            const params: Record<string, string | number> = { cursor: nextCursor.value, per_page: 25 };
            if (projectFilter.value)
                params.project_id = projectFilter.value;
            if (searchQuery.value)
                params.search = searchQuery.value;
            if (showArchived.value)
                params.archived = 1;

            const response = await axios.get('/api/v1/conversations', { params });
            conversations.value.push(...response.data.data);
            nextCursor.value = response.data.meta?.next_cursor || null;
            hasMore.value = !!nextCursor.value;
        } catch (err: unknown) {
            error.value = getErrorMessage(err, 'Failed to load more');
        } finally {
            loading.value = false;
        }
    }

    /**
     * Toggle archive on a conversation. Removes it from the current list.
     */
    async function toggleArchive(conversationId: string): Promise<void> {
        try {
            await axios.patch(`/api/v1/conversations/${conversationId}/archive`);
            conversations.value = conversations.value.filter(c => c.id !== conversationId);
            if (selectedId.value === conversationId) {
                selectedId.value = null;
            }
        } catch (err: unknown) {
            error.value = getErrorMessage(err, 'Failed to archive');
        }
    }

    function setProjectFilter(projectId: number | null): void {
        projectFilter.value = projectId;
        fetchConversations();
    }

    function setSearchQuery(query: string): void {
        searchQuery.value = query;
        fetchConversations();
    }

    function setShowArchived(archived: boolean): void {
        showArchived.value = archived;
        fetchConversations();
    }

    /**
     * Restore pending action from persisted messages after page reload.
     * If the last message is an assistant message with an unconfirmed action_preview
     * block, re-populate pendingAction so the Confirm/Cancel card reappears.
     */
    function restoreActionPreview(rawMessages: ChatMessage[]): void {
        pendingAction.value = null;
        if (rawMessages.length === 0)
            return;
        const lastMsg = rawMessages[rawMessages.length - 1];
        if (lastMsg.role !== 'assistant')
            return;
        const match = lastMsg.content.match(ACTION_PREVIEW_REGEX);
        if (!match)
            return;
        try {
            pendingAction.value = {
                id: `preview-${Date.now()}`,
                ...JSON.parse(match[1].trim()),
            };
        } catch { /* malformed JSON — ignore */ }
    }

    /**
     * Re-subscribe to Reverb channels for tasks that were dispatched but
     * haven't reached terminal status yet. Called after fetchMessages to
     * restore real-time tracking after a page reload.
     */
    function resubscribeActiveTasks(): void {
        const dispatchedTasks = new Map<number, TaskDispatchInfo>();
        const deliveredTaskIds = new Set<number>();

        for (const msg of messages.value) {
            // Check tool_results for dispatch markers (SDK stores tool outputs here)
            const toolResults = msg.tool_results as unknown[];
            if (Array.isArray(toolResults)) {
                for (const tr of toolResults) {
                    const text = String((tr as Record<string, unknown>).result ?? '');
                    const dispatch = parseTaskDispatchMessage(text);
                    if (dispatch) {
                        dispatchedTasks.set(dispatch.taskId, dispatch);
                    }
                }
            }

            // Check for delivery markers (terminal tasks already handled by hydrateResultCards)
            if (msg.role === 'system' && msg.content.includes('[System: Task result delivered]')) {
                const match = msg.content.match(/Task #(\d+)/);
                if (match) {
                    deliveredTaskIds.add(Number.parseInt(match[1], 10));
                }
            }
        }

        // Track and subscribe to tasks that were dispatched but not yet delivered
        for (const [taskId, dispatch] of dispatchedTasks) {
            if (!deliveredTaskIds.has(taskId) && !taskSubscriptions.value.has(taskId)) {
                trackTask({
                    task_id: taskId,
                    status: 'running',
                    type: typeFromLabel(dispatch.typeLabel),
                    title: dispatch.title,
                    project_id: selected.value?.project_id || null,
                    pipeline_id: null,
                    pipeline_status: null,
                    started_at: null,
                    conversation_id: selectedId.value,
                });
                subscribeToTask(taskId);
            }
        }
    }

    /**
     * Fetch messages for a conversation by loading its detail.
     * After loading, hydrates result cards from persisted system messages (T70),
     * restores any unconfirmed action preview (T68), and re-subscribes to
     * still-active task channels for real-time tracking after page reload.
     */
    async function fetchMessages(conversationId: string): Promise<void> {
        messagesLoading.value = true;
        messagesError.value = null;
        try {
            const response = await axios.get(`/api/v1/conversations/${conversationId}`);
            messages.value = response.data.data.messages || [];
            restoreActionPreview(messages.value);
            await hydrateResultCards();
            resubscribeActiveTasks();
        } catch (err: unknown) {
            messagesError.value = getErrorMessage(err, 'Failed to load messages');
            messages.value = [];
        } finally {
            messagesLoading.value = false;
        }
    }

    /**
     * T70: Parse system result messages and fetch task result data for result cards.
     * Called after fetchMessages to hydrate result cards from persisted messages.
     */
    async function hydrateResultCards(): Promise<void> {
        const systemResultMessages = messages.value.filter(
            m => m.role === 'system' && m.content.includes('[System: Task result delivered]'),
        );

        for (const msg of systemResultMessages) {
            const match = msg.content.match(/Task #(\d+)/);
            if (!match)
                continue;
            const taskId = Number.parseInt(match[1], 10);

            // Skip if already hydrated
            if (completedResults.value.some(r => r.task_id === taskId))
                continue;

            try {
                const response = await axios.get(`/api/v1/tasks/${taskId}/view`);
                const data = response.data.data;
                const r = data.result ?? {};
                const resultData: Record<string, unknown> = {
                    branch: r.branch || null,
                    target_branch: r.target_branch || 'main',
                    files_changed: r.files_changed || null,
                    notes: r.notes || null,
                };
                // Type-specific fields (mirrors TaskStatusChanged::buildResultData)
                if (data.type === 'ui_adjustment') {
                    resultData.screenshot = r.screenshot || null;
                }
                if (data.type === 'deep_analysis') {
                    resultData.analysis = r.analysis || null;
                    resultData.key_findings = r.key_findings || null;
                    resultData.references = r.references || null;
                }
                if (data.type === 'issue_discussion') {
                    resultData.response = r.response || null;
                    resultData.references = r.references || null;
                }
                if (data.type === 'prd_creation') {
                    resultData.gitlab_issue_url = r.gitlab_issue_url || null;
                }
                completedResults.value.push({
                    task_id: data.task_id,
                    status: data.status,
                    type: data.type,
                    title: data.title,
                    mr_iid: data.mr_iid,
                    issue_iid: data.issue_iid,
                    result_summary: r.notes || r.summary || null,
                    error_reason: data.error_reason,
                    result_data: resultData,
                    conversation_id: selectedId.value,
                    project_id: data.project_id,
                    gitlab_url: '',
                });
            } catch {
                // Task may have been deleted or user lost access — skip silently
            }
        }
    }

    /**
     * Send a user message to the selected conversation.
     */
    async function sendMessage(content: string): Promise<void> {
        if (!selectedId.value)
            return;
        sending.value = true;
        messagesError.value = null;
        try {
            const response = await axios.post(
                `/api/v1/conversations/${selectedId.value}/messages`,
                { content },
            );
            messages.value.push(response.data.data);
        } catch (err: unknown) {
            messagesError.value = getErrorMessage(err, 'Failed to send message');
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
    async function streamMessage(content: string): Promise<void> {
        if (!selectedId.value)
            return;
        streaming.value = true;
        streamingContent.value = '';
        activeToolCalls.value = [];
        messagesError.value = null;
        streamRetryable.value = false;

        // Optimistic user message
        const userMsg: ChatMessage = {
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
                },
            );

            // Check response status before streaming
            if (!response.ok) {
                messagesError.value = `Request failed with status ${response.status}`;
                streaming.value = false;
                return;
            }

            let accumulated = '';

            await streamSSE(response, {
                onEvent(data: unknown) {
                    const event = data as SSEEvent;
                    if (event.type === 'text_delta' && event.delta) {
                        accumulated += event.delta;
                        streamingContent.value = accumulated;

                        // T68: Detect action_preview code blocks in streamed text
                        if (!pendingAction.value) {
                            const match = accumulated.match(ACTION_PREVIEW_REGEX);
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
                            tool: event.tool_name || 'unknown',
                            input: event.arguments || {},
                        });
                    }
                    if (event.type === 'tool_result') {
                        activeToolCalls.value = activeToolCalls.value.filter(
                            tc => tc.tool !== event.tool_name,
                        );

                        // Detect task dispatch in tool result and subscribe to Reverb channel
                        if (typeof event.result === 'string') {
                            const dispatch = parseTaskDispatchMessage(event.result);
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
                        }
                    }
                },
                onDone() {
                    // Finalize the assistant message
                    const assistantMsg: ChatMessage = {
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
                async onStreamError(error: StreamError) {
                    // D188: Structured error recovery for AI provider failures
                    streaming.value = false;
                    streamingContent.value = '';
                    activeToolCalls.value = [];
                    // Re-fetch persisted messages — RemembersConversations saves server-side
                    // (fetchMessages clears messagesError, so set it AFTER)
                    await fetchMessages(selectedId.value!);
                    streamRetryable.value = error.retryable;
                    messagesError.value = error.message;
                },
                async onError(err: unknown) {
                    messagesError.value = (err as Error).message || 'Stream interrupted';
                    // Connection resilience: re-fetch messages from REST API
                    // The SDK's RemembersConversations stores the complete response
                    await fetchMessages(selectedId.value!);
                },
            });

            streaming.value = false;
        } catch (err: unknown) {
            messagesError.value = (err as Error).message || 'Failed to stream response';
            streaming.value = false;
        }
    }

    async function selectConversation(id: string | null): Promise<void> {
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
    async function createConversation(projectId: number): Promise<Conversation> {
        error.value = null;
        try {
            const response = await axios.post('/api/v1/conversations', {
                project_id: projectId,
            });
            const newConversation = response.data.data;
            conversations.value.unshift(newConversation);
            return newConversation;
        } catch (err: unknown) {
            error.value = getErrorMessage(err, 'Failed to create conversation');
            throw err;
        }
    }

    /**
     * T68: Confirm a pending action preview.
     * Sends a confirmation message to the AI, which will then call DispatchAction.
     */
    async function confirmAction(): Promise<void> {
        if (!pendingAction.value || !selectedId.value)
            return;
        const action = pendingAction.value;
        pendingAction.value = null;
        await streamMessage(`Confirmed. Go ahead with: ${action.title}`);
    }

    /**
     * T68: Cancel a pending action preview.
     * Sends a cancellation message to the AI.
     */
    function cancelAction(): void {
        if (!pendingAction.value)
            return;
        pendingAction.value = null;
        streamMessage('Cancel this action, I changed my mind.');
    }

    /**
     * Add a project to an existing conversation (cross-project D28).
     */
    async function addProjectToConversation(conversationId: string, projectId: number): Promise<unknown> {
        error.value = null;
        try {
            const response = await axios.post(
                `/api/v1/conversations/${conversationId}/projects`,
                { project_id: projectId },
            );
            // Update conversation in list with new data
            const idx = conversations.value.findIndex(c => c.id === conversationId);
            if (idx !== -1) {
                conversations.value[idx] = { ...conversations.value[idx], ...response.data.data };
            }
            return response.data.data;
        } catch (err: unknown) {
            error.value = getErrorMessage(err, 'Failed to add project');
            throw err;
        }
    }

    function $reset(): void {
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
        completedResults.value = [];
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
        streamRetryable,
        pendingAction,
        activeTasks,
        activeTasksForConversation,
        completedResults,
        completedResultsForConversation,
        trackTask,
        updateTaskStatus,
        removeTask,
        deliverTaskResult,
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
        hydrateResultCards,
        sendMessage,
        streamMessage,
        confirmAction,
        cancelAction,
        createConversation,
        addProjectToConversation,
        $reset,
    };
});
