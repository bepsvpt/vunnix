<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useDashboardStore } from '@/stores/dashboard';
import BaseCard from './ui/BaseCard.vue';
import BaseEmptyState from './ui/BaseEmptyState.vue';
import BaseSpinner from './ui/BaseSpinner.vue';

const dashboard = useDashboardStore();

onMounted(() => {
    dashboard.fetchDesignerActivity();
});

const designerActivity = computed(() => dashboard.designerActivity);

const avgIterationsDisplay = computed(() => {
    if (!designerActivity.value || designerActivity.value.avg_iterations === null)
        return '—';
    return `${designerActivity.value.avg_iterations}`;
});

const successRateDisplay = computed(() => {
    if (!designerActivity.value || designerActivity.value.first_attempt_success_rate === null)
        return '—';
    return `${designerActivity.value.first_attempt_success_rate}%`;
});
</script>

<template>
    <div data-testid="dashboard-designer-activity">
        <!-- Loading state -->
        <div
            v-if="dashboard.designerActivityLoading && !designerActivity"
            data-testid="designer-activity-loading"
            class="flex items-center justify-center py-12"
        >
            <BaseSpinner size="md" />
        </div>

        <!-- Designer Activity cards -->
        <div v-else-if="designerActivity" class="space-y-6">
            <!-- Top row: UI Adjustments Dispatched + Avg Iterations -->
            <div class="grid grid-cols-2 gap-4">
                <BaseCard
                    data-testid="ui-adjustments-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        UI Adjustments
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="ui-adjustments-value">
                        {{ designerActivity.ui_adjustments_dispatched }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Completed UI adjustment tasks
                    </p>
                </BaseCard>

                <BaseCard
                    data-testid="avg-iterations-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Avg Iterations
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="avg-iterations-value">
                        {{ avgIterationsDisplay }}
                    </p>
                    <p v-if="designerActivity.avg_iterations === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                        No adjustments yet
                    </p>
                    <p v-else class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Attempts per UI adjustment
                    </p>
                </BaseCard>
            </div>

            <!-- Bottom row: MRs from Chat + First-Attempt Success Rate -->
            <div class="grid grid-cols-2 gap-4">
                <BaseCard
                    data-testid="mrs-from-chat-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        MRs from Chat
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="mrs-from-chat-value">
                        {{ designerActivity.mrs_created_from_chat }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Merge requests created via conversation
                    </p>
                </BaseCard>

                <BaseCard
                    data-testid="success-rate-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        First-Attempt Success
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="success-rate-value">
                        {{ successRateDisplay }}
                    </p>
                    <p v-if="designerActivity.first_attempt_success_rate === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                        No adjustments yet
                    </p>
                    <p v-else class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Completed on first attempt
                    </p>
                </BaseCard>
            </div>
        </div>

        <!-- Empty state -->
        <BaseEmptyState v-else data-testid="designer-activity-empty">
            <template #description>
                No designer activity data available.
            </template>
        </BaseEmptyState>
    </div>
</template>
