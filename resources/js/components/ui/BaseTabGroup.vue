<script setup lang="ts">
interface Tab {
    key: string;
    label: string;
}

interface Props {
    tabs: Tab[];
    modelValue: string;
}

defineProps<Props>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();
</script>

<template>
    <nav class="flex gap-1 border-b border-zinc-200 dark:border-zinc-800 overflow-x-auto [scrollbar-width:none] [-webkit-overflow-scrolling:touch] [&::-webkit-scrollbar]:hidden">
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            class="px-4 py-2.5 text-sm font-medium whitespace-nowrap -mb-px border-b-2 transition-colors"
            :class="modelValue === tab.key
                ? 'border-zinc-900 dark:border-zinc-100 text-zinc-900 dark:text-zinc-100'
                : 'border-transparent text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300 hover:border-zinc-300 dark:hover:border-zinc-600'"
            :data-testid="`tab-${tab.key}`"
            @click="emit('update:modelValue', tab.key)"
        >
            {{ tab.label }}
        </button>
    </nav>
</template>
