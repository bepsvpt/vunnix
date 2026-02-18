<script setup lang="ts">
</script>

<template>
    <div class="flex flex-col items-center justify-center py-16 px-4 text-center">
        <div
            v-if="$slots.icon"
            class="w-12 h-12 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mb-4 text-xl"
        >
            <slot name="icon" />
        </div>
        <h3
            v-if="$slots.title"
            class="text-sm font-medium text-zinc-900 dark:text-zinc-100 mb-1"
        >
            <slot name="title" />
        </h3>
        <p
            v-if="$slots.description"
            class="text-sm text-zinc-500 dark:text-zinc-400 max-w-sm mb-4"
        >
            <slot name="description" />
        </p>
        <div v-if="$slots.action">
            <slot name="action" />
        </div>
    </div>
</template>
