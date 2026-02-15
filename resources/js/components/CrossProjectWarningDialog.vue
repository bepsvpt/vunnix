<script setup>
const props = defineProps({
    existingProjectName: { type: String, required: true },
    newProjectName: { type: String, required: true },
});

const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="emit('cancel')">
    <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
      <div class="flex items-center gap-3 mb-4">
        <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
        </div>
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Cross-Project Visibility Warning</h2>
      </div>

      <p class="text-sm text-zinc-700 dark:text-zinc-300 mb-4">
        Adding <strong>{{ newProjectName }}</strong> will make this conversation &mdash; including AI-browsed content from <strong>{{ existingProjectName }}</strong> &mdash; visible to all members of <strong>{{ newProjectName }}</strong>.
      </p>

      <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-6">
        This cannot be undone. Members of the added project will be able to see the full conversation history.
      </p>

      <div class="flex justify-end gap-2">
        <button
          type="button"
          class="px-4 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
          @click="emit('cancel')"
        >
          Cancel
        </button>
        <button
          type="button"
          class="px-4 py-2 text-sm rounded-lg bg-amber-600 text-white hover:bg-amber-700 transition-colors"
          @click="emit('confirm')"
        >
          Continue
        </button>
      </div>
    </div>
  </div>
</template>
