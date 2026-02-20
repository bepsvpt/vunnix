<script setup lang="ts">
import { computed } from 'vue';
import MarkdownContent from './MarkdownContent.vue';

interface ChatMessage {
    id: string;
    role: string;
    content: string;
    created_at: string | null;
}

interface Props {
    message: ChatMessage;
}

const props = defineProps<Props>();

const isUser = computed(() => props.message.role === 'user');

const formattedTime = computed(() => {
    const date = new Date(props.message.created_at ?? Date.now());
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
});
</script>

<template>
    <div
        class="flex w-full"
        :class="isUser ? 'justify-end' : 'justify-start'"
    >
        <div
            class="rounded-[var(--radius-bubble)]"
            :class="isUser
                ? 'max-w-md px-4 py-3 text-sm leading-relaxed bg-blue-600 text-white rounded-br-sm'
                : 'max-w-2xl px-5 py-4 bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 rounded-bl-sm'"
            :data-role="message.role"
        >
            <!-- User messages: plain text -->
            <p v-if="isUser" class="whitespace-pre-wrap break-words">
                {{ message.content }}
            </p>

            <!-- Assistant messages: rendered markdown -->
            <div v-else class="chat-bubble">
                <MarkdownContent :content="message.content" />
            </div>

            <div
                data-testid="timestamp"
                class="mt-1.5 text-[11px] opacity-50"
                :class="isUser ? 'text-right' : 'text-left'"
            >
                {{ formattedTime }}
            </div>
        </div>
    </div>
</template>
