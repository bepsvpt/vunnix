<script setup>
import { onMounted, onUnmounted, ref } from 'vue';

defineProps({
    tasks: { type: Array, required: true },
});

const TASK_TYPE_DISPLAY = {
    code_review: { label: 'Code Review', emoji: '🔍' },
    feature_dev: { label: 'Feature Dev', emoji: '🚀' },
    ui_adjustment: { label: 'UI Adjustment', emoji: '🎨' },
    prd_creation: { label: 'Issue Creation', emoji: '📋' },
    deep_analysis: { label: 'Deep Analysis', emoji: '🔬' },
    security_audit: { label: 'Security Audit', emoji: '🔒' },
    issue_discussion: { label: 'Issue Discussion', emoji: '💬' },
};

function typeDisplay(type) {
    return TASK_TYPE_DISPLAY[type] || { label: type, emoji: '⚙️' };
}

// Reactive tick for elapsed time updates
const tick = ref(Date.now());
let intervalId = null;

onMounted(() => {
    intervalId = setInterval(() => {
        tick.value = Date.now();
    }, 1000);
});

onUnmounted(() => {
    if (intervalId)
        clearInterval(intervalId);
});

function formatElapsed(startedAt) {
    if (!startedAt)
        return null;
    const start = new Date(startedAt).getTime();
    // Use tick.value to make this reactive every second
    const diff = Math.max(0, Math.floor((tick.value - start) / 1000));
    const minutes = Math.floor(diff / 60);
    const seconds = diff % 60;
    return `${minutes}m ${seconds}s`;
}

function isPipelinePending(task) {
    return task.pipeline_status === 'pending';
}
</script>

<template>
    <div v-if="tasks.length > 0" data-testid="pinned-task-bar" class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
        <div
            v-for="task in tasks"
            :key="task.task_id"
            data-testid="pinned-task-item"
            class="flex items-center gap-3 px-4 py-2 text-sm"
        >
            <!-- Action type badge -->
            <span
                data-testid="task-type-badge"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 shrink-0"
            >
                <span>{{ typeDisplay(task.type).emoji }}</span>
                <span class="hidden sm:inline">{{ typeDisplay(task.type).label }}</span>
            </span>

            <!-- Status & title -->
            <div class="flex-1 min-w-0">
                <template v-if="isPipelinePending(task)">
                    <span class="text-amber-600 dark:text-amber-400">
                        ⏳ Waiting for available runner…
                    </span>
                    <span class="text-zinc-500 dark:text-zinc-400 text-xs ml-1">
                        — System busy, expect delays
                    </span>
                </template>
                <template v-else-if="task.status === 'running'">
                    <span class="text-zinc-700 dark:text-zinc-200">
                        ⏳ {{ task.title || 'Running task…' }}
                    </span>
                </template>
                <template v-else>
                    <span class="text-zinc-500 dark:text-zinc-400">
                        ⏳ Queued — {{ task.title || 'Waiting…' }}
                    </span>
                </template>
            </div>

            <!-- Elapsed time -->
            <span
                v-if="task.started_at"
                data-testid="elapsed-time"
                class="text-xs text-zinc-500 dark:text-zinc-400 tabular-nums shrink-0"
            >
                {{ formatElapsed(task.started_at) }}
            </span>

            <!-- Pipeline link -->
            <a
                v-if="task.pipeline_id"
                data-testid="pipeline-link"
                :href="`/-/pipelines/${task.pipeline_id}`"
                target="_blank"
                rel="noopener"
                class="text-xs text-blue-600 dark:text-blue-400 hover:underline shrink-0"
            >
                View pipeline ↗
            </a>
        </div>
    </div>
</template>
