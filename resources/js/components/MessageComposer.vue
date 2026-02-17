<script setup lang="ts">
import { ref } from 'vue';

interface Props {
    disabled?: boolean;
}

withDefaults(defineProps<Props>(), {
    disabled: false,
});

const emit = defineEmits<{
    send: [content: string];
}>();
const input = ref('');

function handleSubmit() {
    const trimmed = input.value.trim();
    if (!trimmed)
        return;
    emit('send', trimmed);
    input.value = '';
}

function handleKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        handleSubmit();
    }
}
</script>

<template>
    <form
        class="flex items-end gap-2 border-t border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4"
        @submit.prevent="handleSubmit"
    >
        <textarea
            v-model="input"
            :disabled="disabled"
            rows="1"
            class="flex-1 resize-none rounded-xl border border-zinc-300 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 px-4 py-2.5 text-sm text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:opacity-50"
            placeholder="Type a message…"
            @keydown="handleKeydown"
        />
        <button
            type="submit"
            :disabled="disabled"
            class="shrink-0 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
            Send
        </button>
    </form>
</template>
