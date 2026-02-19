<script setup lang="ts">
import { computed, onMounted } from 'vue';
import BaseBadge from '@/components/ui/BaseBadge.vue';
import BaseCard from '@/components/ui/BaseCard.vue';
import BaseEmptyState from '@/components/ui/BaseEmptyState.vue';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import { useProjectMemory } from '@/composables/useProjectMemory';

interface Props {
    projectId: number;
}

const props = defineProps<Props>();
const memory = useProjectMemory(props.projectId);
const stats = computed(() => memory.stats.value);
const statsLoading = computed(() => memory.statsLoading.value);

const avgWidth = computed(() => {
    const value = Math.max(0, Math.min(100, Math.round(stats.value?.average_confidence ?? 0)));
    return `${value}%`;
});

onMounted(async () => {
    await memory.fetchStats();
});
</script>

<template>
    <BaseCard data-testid="memory-stats-widget">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                    Project Memory
                </h3>
                <BaseBadge variant="info">
                    {{ stats?.total_entries ?? 0 }} patterns
                </BaseBadge>
            </div>

            <div
                v-if="statsLoading"
                class="flex justify-center py-4"
                data-testid="memory-stats-loading"
            >
                <BaseSpinner size="sm" />
            </div>

            <BaseEmptyState v-else-if="!stats || stats.total_entries === 0" data-testid="memory-stats-empty">
                <template #title>
                    No learned patterns yet
                </template>
                <template #description>
                    Memory stats appear after review and conversation extraction jobs run.
                </template>
            </BaseEmptyState>

            <template v-else>
                <div class="flex flex-wrap gap-2" data-testid="memory-stats-badges">
                    <BaseBadge variant="neutral">
                        Review: {{ stats.by_type.review_pattern ?? 0 }}
                    </BaseBadge>
                    <BaseBadge variant="neutral">
                        Conversation: {{ stats.by_type.conversation_fact ?? 0 }}
                    </BaseBadge>
                    <BaseBadge variant="neutral">
                        Cross-MR: {{ stats.by_type.cross_mr_pattern ?? 0 }}
                    </BaseBadge>
                </div>

                <div class="space-y-1">
                    <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                        <span>Average Confidence</span>
                        <span>{{ Math.round(stats.average_confidence) }}%</span>
                    </div>
                    <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                        <div class="h-full bg-blue-500 transition-all duration-300" :style="{ width: avgWidth }" data-testid="memory-confidence-bar" />
                    </div>
                </div>

                <p class="text-xs text-zinc-500 dark:text-zinc-400" data-testid="memory-last-extraction-display">
                    Last extraction:
                    {{ stats.last_created_at ? new Date(stats.last_created_at).toLocaleString() : '—' }}
                </p>
            </template>
        </div>
    </BaseCard>
</template>
