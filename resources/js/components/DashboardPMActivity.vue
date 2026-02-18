<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useDashboardStore } from '@/stores/dashboard';
import BaseCard from './ui/BaseCard.vue';
import BaseEmptyState from './ui/BaseEmptyState.vue';
import BaseSpinner from './ui/BaseSpinner.vue';

const dashboard = useDashboardStore();

onMounted(() => {
    dashboard.fetchPMActivity();
});

const pmActivity = computed(() => dashboard.pmActivity);

const avgTurnsDisplay = computed(() => {
    if (!pmActivity.value || pmActivity.value.avg_turns_per_prd === null)
        return '—';
    return `${pmActivity.value.avg_turns_per_prd}`;
});
</script>

<template>
    <div data-testid="dashboard-pm-activity">
        <!-- Loading state -->
        <div
            v-if="dashboard.pmActivityLoading && !pmActivity"
            data-testid="pm-activity-loading"
            class="flex items-center justify-center py-12"
        >
            <BaseSpinner size="md" />
        </div>

        <!-- PM Activity cards -->
        <div v-else-if="pmActivity" class="space-y-6">
            <!-- Top row: PRDs Created + Conversations Held -->
            <div class="grid grid-cols-2 gap-4">
                <BaseCard
                    data-testid="prds-created-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        PRDs Created
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="prds-created-value">
                        {{ pmActivity.prds_created }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Issues created from PRD conversations
                    </p>
                </BaseCard>

                <BaseCard
                    data-testid="conversations-held-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Conversations Held
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="conversations-held-value">
                        {{ pmActivity.conversations_held }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Total chat conversations
                    </p>
                </BaseCard>
            </div>

            <!-- Bottom row: Issues from Chat + Avg Turns per PRD -->
            <div class="grid grid-cols-2 gap-4">
                <BaseCard
                    data-testid="issues-from-chat-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Issues from Chat
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="issues-from-chat-value">
                        {{ pmActivity.issues_from_chat }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Issues created via conversation dispatch
                    </p>
                </BaseCard>

                <BaseCard
                    data-testid="avg-turns-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Avg Turns / PRD
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="avg-turns-value">
                        {{ avgTurnsDisplay }}
                    </p>
                    <p v-if="pmActivity.avg_turns_per_prd === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                        No PRDs yet
                    </p>
                    <p v-else class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Conversation messages before PRD creation
                    </p>
                </BaseCard>
            </div>
        </div>

        <!-- Empty state -->
        <BaseEmptyState v-else data-testid="pm-activity-empty">
            <template #description>
                No PM activity data available.
            </template>
        </BaseEmptyState>
    </div>
</template>
