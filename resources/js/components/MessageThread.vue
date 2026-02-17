<script setup>
import { nextTick, ref, watch } from 'vue';
import { useConversationsStore } from '@/stores/conversations';
import ActionPreviewCard from './ActionPreviewCard.vue';
import MarkdownContent from './MarkdownContent.vue';
import MessageBubble from './MessageBubble.vue';
import MessageComposer from './MessageComposer.vue';
import PinnedTaskBar from './PinnedTaskBar.vue';
import ResultCard from './ResultCard.vue';
import ToolUseIndicators from './ToolUseIndicators.vue';
import TypingIndicator from './TypingIndicator.vue';

function buildResultCardProps(result) {
    return {
        task_id: result.task_id,
        status: result.status,
        type: result.type,
        title: result.title,
        mr_iid: result.mr_iid,
        issue_iid: result.issue_iid,
        branch: result.result_data?.branch || null,
        target_branch: result.result_data?.target_branch || 'main',
        files_changed: result.result_data?.files_changed || null,
        result_summary: result.result_summary,
        error_reason: result.error_reason,
        screenshot: result.result_data?.screenshot || null,
        project_id: result.project_id,
        gitlab_url: result.gitlab_url || '',
    };
}

const store = useConversationsStore();
const scrollContainer = ref(null);

async function scrollToBottom() {
    await nextTick();
    if (scrollContainer.value) {
        scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight;
    }
}

// Auto-scroll when messages change, streaming content updates, tool calls change, or action preview appears
watch(() => store.messages.length, scrollToBottom);
watch(() => store.streamingContent, scrollToBottom);
watch(() => store.activeToolCalls.length, scrollToBottom);
watch(() => store.pendingAction, scrollToBottom);

async function handleSend(content) {
    await store.streamMessage(content);
    scrollToBottom();
}
</script>

<template>
    <div class="flex flex-col h-full">
        <!-- Messages area -->
        <div ref="scrollContainer" class="flex-1 overflow-y-auto px-4 py-4">
            <!-- Loading -->
            <div
                v-if="store.messagesLoading"
                data-testid="messages-loading"
                class="flex items-center justify-center h-full"
            >
                <svg class="animate-spin h-6 w-6 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
            </div>

            <!-- Error -->
            <div
                v-else-if="store.messagesError"
                class="flex items-center justify-center h-full"
            >
                <p class="text-sm text-red-500">
                    {{ store.messagesError }}
                </p>
            </div>

            <!-- Empty state (suppressed when streaming) -->
            <div
                v-else-if="store.messages.length === 0 && !store.streaming"
                data-testid="empty-thread"
                class="flex items-center justify-center h-full"
            >
                <div class="text-center text-zinc-400 dark:text-zinc-500">
                    <svg class="w-10 h-10 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                    <p class="text-sm">
                        Send a message to start the conversation
                    </p>
                </div>
            </div>

            <!-- Messages -->
            <div v-else class="space-y-3 max-w-3xl mx-auto">
                <MessageBubble
                    v-for="message in store.messages"
                    :key="message.id"
                    :message="message"
                />

                <!-- Result cards: completed task results delivered via Reverb (T70) -->
                <div
                    v-for="result in store.completedResultsForConversation"
                    :key="`result-${result.task_id}`"
                    class="flex w-full justify-start"
                >
                    <ResultCard :result="buildResultCardProps(result)" />
                </div>

                <!-- Tool-use activity indicators: shows what tools the AI is calling -->
                <ToolUseIndicators
                    v-if="store.streaming"
                    :tool-calls="store.activeToolCalls"
                />

                <!-- Action preview card: structured Confirm/Cancel card for action dispatches (T68) -->
                <div v-if="store.pendingAction" class="flex w-full justify-start">
                    <ActionPreviewCard
                        :action="store.pendingAction"
                        @confirm="store.confirmAction()"
                        @cancel="store.cancelAction()"
                    />
                </div>

                <!-- Streaming bubble: shows partial assistant response during SSE streaming -->
                <div v-if="store.streaming && store.streamingContent" class="flex w-full justify-start">
                    <div
                        data-testid="streaming-bubble"
                        class="max-w-[80%] rounded-2xl px-4 py-3 bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 rounded-bl-md"
                    >
                        <MarkdownContent :content="store.streamingContent" />
                    </div>
                </div>

                <!-- Typing indicator: pulsing dots while streaming -->
                <TypingIndicator v-if="store.streaming" />
            </div>
        </div>

        <!-- Pinned task bar: active tasks with elapsed time (T69) -->
        <PinnedTaskBar
            v-if="store.activeTasksForConversation.length > 0"
            :tasks="store.activeTasksForConversation"
        />

        <!-- Composer -->
        <MessageComposer :disabled="store.sending || store.streaming || !!store.pendingAction" @send="handleSend" />
    </div>
</template>
