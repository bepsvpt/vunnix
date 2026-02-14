<script setup>
import { computed } from 'vue';

const props = defineProps({
    conversation: { type: Object, required: true },
    projectName: { type: String, default: '' },
    isSelected: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'archive']);

const relativeTime = computed(() => {
    const date = new Date(props.conversation.updated_at);
    const now = new Date();
    const diffMs = now - date;
    const diffMin = Math.floor(diffMs / 60000);
    const diffHr = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHr / 24);
    if (diffMin < 1) return 'just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    if (diffHr < 24) return `${diffHr}h ago`;
    if (diffDay < 30) return `${diffDay}d ago`;
    return date.toLocaleDateString();
});

const lastMessagePreview = computed(() => {
    if (!props.conversation.last_message) return 'No messages yet';
    return props.conversation.last_message.content;
});
</script>

<template>
  <button
    type="button"
    class="w-full text-left px-3 py-3 rounded-lg transition-colors group"
    :class="isSelected
      ? 'bg-zinc-100 dark:bg-zinc-800'
      : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50'"
    @click="emit('select', conversation.id)"
  >
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
          <h3
            class="text-sm truncate"
            :class="isSelected
              ? 'font-semibold text-zinc-900 dark:text-zinc-100'
              : 'font-medium text-zinc-700 dark:text-zinc-300'"
          >
            {{ conversation.title }}
          </h3>
          <span
            v-if="projectName"
            class="shrink-0 text-xs px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400"
          >
            {{ projectName }}
          </span>
        </div>
        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 truncate">
          {{ lastMessagePreview }}
        </p>
      </div>
      <div class="flex items-center gap-1 shrink-0">
        <span class="text-xs text-zinc-400 dark:text-zinc-500">
          {{ relativeTime }}
        </span>
        <button
          type="button"
          class="p-1 rounded opacity-0 group-hover:opacity-100 hover:bg-zinc-200 dark:hover:bg-zinc-700 transition-opacity"
          :title="conversation.archived_at ? 'Unarchive' : 'Archive'"
          @click.stop="emit('archive', conversation.id)"
        >
          <svg class="w-3.5 h-3.5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
          </svg>
        </button>
      </div>
    </div>
  </button>
</template>
