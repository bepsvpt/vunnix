<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    padded?: boolean;
    hoverable?: boolean;
    variant?: 'default' | 'success' | 'danger';
}

const props = withDefaults(defineProps<Props>(), {
    padded: true,
    hoverable: false,
    variant: 'default',
});

const variantClasses: Record<string, string> = {
    default: 'border-zinc-200 dark:border-zinc-700',
    success: 'border-emerald-200 dark:border-emerald-800',
    danger: 'border-red-200 dark:border-red-800',
};

const classes = computed(() => [
    variantClasses[props.variant],
    props.padded ? 'p-[var(--spacing-card)]' : '',
    props.hoverable ? 'transition-shadow hover:shadow-[var(--shadow-card-hover)]' : '',
]);
</script>

<template>
    <div
        class="rounded-[var(--radius-card)] border bg-white dark:bg-zinc-900 shadow-[var(--shadow-card)]"
        :class="classes"
    >
        <slot />
    </div>
</template>
