<script setup>
import ConversationList from '@/components/ConversationList.vue';
import { useConversationsStore } from '@/stores/conversations';

const store = useConversationsStore();
</script>

<template>
  <div class="flex h-[calc(100vh-4rem)] -m-4 lg:-m-8">
    <!-- Sidebar: conversation list -->
    <aside class="w-80 flex-shrink-0 border-r border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
      <ConversationList />
    </aside>

    <!-- Main area: placeholder for message thread (T65) -->
    <main class="flex-1 flex items-center justify-center">
      <div v-if="store.selectedId" class="text-center text-zinc-500 dark:text-zinc-400">
        <p class="text-sm">Message thread coming in T65.</p>
      </div>
      <div v-else class="text-center text-zinc-400 dark:text-zinc-500">
        <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
        </svg>
        <p class="text-sm">Select a conversation to get started</p>
      </div>
    </main>
  </div>
</template>
