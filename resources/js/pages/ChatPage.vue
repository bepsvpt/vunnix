<script setup lang="ts">
import { watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ConversationList from '@/components/ConversationList.vue';
import MessageThread from '@/components/MessageThread.vue';
import { useConversationsStore } from '@/features/chat';

const store = useConversationsStore();
const route = useRoute();
const router = useRouter();

// Sync route param → store selection.
// When URL is /chat/:id, select that conversation.
// When URL is /chat (no id), deselect.
watch(
    () => route.params.id as string | undefined,
    (id) => {
        if (id && id !== store.selectedId) {
            store.selectConversation(id);
        } else if (!id && store.selectedId) {
            store.selectConversation(null);
        }
    },
    { immediate: true },
);

// Sync store → route for archive clearing selectedId.
// When the store nullifies selectedId (e.g., archiving the active conversation),
// navigate back to /chat so the URL stays consistent.
watch(
    () => store.selectedId,
    (newId) => {
        const routeId = route.params.id as string | undefined;
        if (!newId && routeId) {
            router.replace({ name: 'chat' });
        }
    },
);
</script>

<template>
    <div class="flex h-full overflow-hidden">
        <!-- Sidebar: conversation list -->
        <aside class="w-80 flex-shrink-0 border-r border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
            <ConversationList />
        </aside>

        <!-- Main area: message thread or empty state -->
        <main class="flex-1 flex flex-col min-w-0">
            <MessageThread v-if="store.selectedId" />
            <div v-else class="flex-1 flex items-center justify-center">
                <div class="text-center text-zinc-400 dark:text-zinc-500">
                    <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                    <p class="text-sm">
                        Select a conversation to get started
                    </p>
                </div>
            </div>
        </main>
    </div>
</template>
