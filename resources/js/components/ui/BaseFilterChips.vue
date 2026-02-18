<script setup lang="ts">
interface Chip {
    label: string;
    value: string | null;
}

interface Props {
    chips: Chip[];
    modelValue: string | null;
}

defineProps<Props>();

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();
</script>

<template>
    <div class="flex flex-wrap gap-1.5">
        <button
            v-for="chip in chips"
            :key="chip.label"
            type="button"
            class="px-3 py-1 text-xs font-medium rounded-full transition-colors"
            :class="modelValue === chip.value
                ? 'bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900'
                : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200 dark:hover:bg-zinc-700'"
            :data-testid="`chip-${chip.value ?? 'all'}`"
            @click="emit('update:modelValue', chip.value)"
        >
            {{ chip.label }}
        </button>
    </div>
</template>
