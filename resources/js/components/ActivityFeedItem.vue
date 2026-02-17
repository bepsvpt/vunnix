<script setup lang="ts">
import type { Activity } from '@/types';
import { computed } from 'vue';

interface Props {
    item: Activity;
}

const props = defineProps<Props>();

const typeIcons: Record<string, string> = {
    code_review: '\uD83D\uDD0D',
    feature_dev: '\u2699\uFE0F',
    ui_adjustment: '\uD83C\uDFA8',
    prd_creation: '\uD83D\uDCCB',
};

const typeLabels: Record<string, string> = {
    code_review: 'Code Review',
    feature_dev: 'Feature Dev',
    ui_adjustment: 'UI Adjustment',
    prd_creation: 'PRD',
};

const statusConfig: Record<string, { icon: string; classes: string }> = {
    queued: { icon: '\u23F3', classes: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' },
    running: { icon: '\u23F3', classes: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' },
    completed: { icon: '\u2705', classes: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' },
    failed: { icon: '\u274C', classes: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' },
};

const typeIcon = computed(() => typeIcons[props.item.type] || '\u2699\uFE0F');
const typeLabel = computed(() => typeLabels[props.item.type] || props.item.type);
const status = computed(() => statusConfig[props.item.status] || statusConfig.queued);

const relativeTime = computed(() => {
    const date = new Date(props.item.created_at ?? '');
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMin = Math.floor(diffMs / 60000);
    const diffHr = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHr / 24);
    if (diffMin < 1)
        return 'just now';
    if (diffMin < 60)
        return `${diffMin}m ago`;
    if (diffHr < 24)
        return `${diffHr}h ago`;
    if (diffDay < 30)
        return `${diffDay}d ago`;
    return date.toLocaleDateString();
});

const linkText = computed(() => {
    if (props.item.mr_iid)
        return `MR !${props.item.mr_iid}`;
    if (props.item.issue_iid)
        return `Issue #${props.item.issue_iid}`;
    if (props.item.conversation_id)
        return 'View conversation';
    return null;
});
</script>

<template>
    <div
        data-testid="activity-item"
        class="flex items-start gap-3 px-4 py-3 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
    >
        <!-- Type icon -->
        <span data-testid="activity-type-icon" class="text-lg mt-0.5 shrink-0" :title="typeLabel">
            {{ typeIcon }}
        </span>

        <!-- Content -->
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <span
                    data-testid="activity-project"
                    class="text-xs font-medium px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-400"
                >
                    {{ item.project_name }}
                </span>
                <span
                    data-testid="activity-status-badge"
                    class="text-xs px-1.5 py-0.5 rounded font-medium"
                    :class="status.classes"
                >
                    {{ status.icon }} {{ item.status }}
                </span>
            </div>

            <p
                v-if="item.summary"
                data-testid="activity-summary"
                class="mt-1 text-sm text-zinc-800 dark:text-zinc-200 truncate"
            >
                {{ item.summary }}
            </p>

            <div class="mt-1 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                <span v-if="item.user_name">{{ item.user_name }}</span>
                <span v-if="item.user_name">&middot;</span>
                <span data-testid="activity-timestamp">{{ relativeTime }}</span>
                <span v-if="linkText">&middot;</span>
                <span v-if="linkText" class="text-blue-600 dark:text-blue-400">{{ linkText }}</span>
            </div>
        </div>
    </div>
</template>
