<script setup>
import { computed } from 'vue';
import MarkdownContent from './MarkdownContent.vue';

const props = defineProps({
    message: { type: Object, required: true },
});

const isUser = computed(() => props.message.role === 'user');

const formattedTime = computed(() => {
    const date = new Date(props.message.created_at);
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
});
</script>

<template>
    <div
        class="flex w-full"
        :class="isUser ? 'justify-end' : 'justify-start'"
    >
        <div
            class="max-w-[80%] rounded-2xl px-4 py-3"
            :class="isUser
                ? 'bg-blue-600 text-white rounded-br-md'
                : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 rounded-bl-md'"
            :data-role="message.role"
        >
            <!-- User messages: plain text -->
            <p v-if="isUser" class="text-sm whitespace-pre-wrap break-words">
                {{ message.content }}
            </p>

            <!-- Assistant messages: rendered markdown -->
            <MarkdownContent v-else :content="message.content" />

            <div
                data-testid="timestamp"
                class="mt-1 text-xs opacity-60"
                :class="isUser ? 'text-right' : 'text-left'"
            >
                {{ formattedTime }}
            </div>
        </div>
    </div>
</template>
