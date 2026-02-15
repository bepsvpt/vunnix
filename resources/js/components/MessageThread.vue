<script setup>
import { ref, watch, nextTick } from 'vue';
import { useConversationsStore } from '@/stores/conversations';
import MessageBubble from './MessageBubble.vue';
import MessageComposer from './MessageComposer.vue';

const store = useConversationsStore();
const scrollContainer = ref(null);

async function scrollToBottom() {
    await nextTick();
    if (scrollContainer.value) {
        scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight;
    }
}

// Auto-scroll when messages change
watch(() => store.messages.length, scrollToBottom);

async function handleSend(content) {
    await store.sendMessage(content);
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
        <p class="text-sm text-red-500">{{ store.messagesError }}</p>
      </div>

      <!-- Empty state -->
      <div
        v-else-if="store.messages.length === 0"
        data-testid="empty-thread"
        class="flex items-center justify-center h-full"
      >
        <div class="text-center text-zinc-400 dark:text-zinc-500">
          <svg class="w-10 h-10 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>
          <p class="text-sm">Send a message to start the conversation</p>
        </div>
      </div>

      <!-- Messages -->
      <div v-else class="space-y-3 max-w-3xl mx-auto">
        <MessageBubble
          v-for="message in store.messages"
          :key="message.id"
          :message="message"
        />
      </div>
    </div>

    <!-- Composer -->
    <MessageComposer :disabled="store.sending" @send="handleSend" />
  </div>
</template>
