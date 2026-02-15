<template>
  <div data-testid="typing-indicator" class="flex w-full justify-start">
    <div class="flex items-center gap-1 rounded-2xl px-4 py-3 bg-zinc-100 dark:bg-zinc-800 rounded-bl-md">
      <span class="typing-dot w-2 h-2 rounded-full bg-zinc-400 dark:bg-zinc-500 animate-bounce" style="animation-delay: 0ms" />
      <span class="typing-dot w-2 h-2 rounded-full bg-zinc-400 dark:bg-zinc-500 animate-bounce" style="animation-delay: 150ms" />
      <span class="typing-dot w-2 h-2 rounded-full bg-zinc-400 dark:bg-zinc-500 animate-bounce" style="animation-delay: 300ms" />
    </div>
  </div>
</template>
