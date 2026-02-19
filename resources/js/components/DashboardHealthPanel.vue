<script setup lang="ts">
import type { HealthDimension } from '@/types';
import { computed, onMounted, onUnmounted, watch } from 'vue';
import HealthAlertCard from '@/components/HealthAlertCard.vue';
import HealthTrendChart from '@/components/HealthTrendChart.vue';
import BaseCard from '@/components/ui/BaseCard.vue';
import BaseEmptyState from '@/components/ui/BaseEmptyState.vue';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import { getEcho, whenConnected } from '@/composables/useEcho';
import { useHealthStore } from '@/stores/health';

interface Props {
    projectId: number;
}

const props = defineProps<Props>();
const health = useHealthStore();

const thresholdMap: Record<HealthDimension, { warning: number; critical: number }> = {
    coverage: { warning: 70, critical: 50 },
    dependency: { warning: 70, critical: 50 },
    complexity: { warning: 50, critical: 30 },
};

const hasSummaryData = computed(() => {
    if (!health.summary) {
        return false;
    }

    return ['coverage', 'dependency', 'complexity'].some((dimension) => {
        const metric = health.summary?.[dimension as HealthDimension];
        return metric?.score !== null && metric?.score !== undefined;
    });
});

const selectedThresholds = computed(() => thresholdMap[health.selectedDimension]);

function trendArrow(direction: string): string {
    if (direction === 'up')
        return '↑';
    if (direction === 'down')
        return '↓';
    return '→';
}

function scoreLabel(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return `${value.toFixed(1)}`;
}

function lastCheckedLabel(value: string | null): string {
    if (!value) {
        return 'Not yet analyzed';
    }

    return new Date(value).toLocaleString();
}

async function loadData(): Promise<void> {
    health.loading = true;
    try {
        await Promise.all([
            health.fetchSummary(props.projectId),
            health.fetchAlerts(props.projectId),
            health.fetchTrends(props.projectId, health.selectedDimension),
        ]);
    } finally {
        health.loading = false;
    }
}

async function selectDimension(dimension: HealthDimension): Promise<void> {
    await health.fetchTrends(props.projectId, dimension);
}

const channelName = `project.${props.projectId}.health`;

function subscribeRealtime(): void {
    whenConnected().then(() => {
        getEcho()
            .private(channelName)
            .listen('.health.snapshot.recorded', (event: unknown) => {
                const payload = event as {
                    dimension: HealthDimension;
                    score: number;
                    trend_direction: 'up' | 'down' | 'stable';
                    created_at: string | null;
                };

                health.applyRealtimeSnapshot(payload);

                if (payload.dimension === health.selectedDimension) {
                    health.fetchTrends(props.projectId, health.selectedDimension);
                }
                health.fetchAlerts(props.projectId);
            });
    });
}

function unsubscribeRealtime(): void {
    getEcho().leave(channelName);
}

watch(() => health.selectedDimension, (dimension) => {
    health.fetchTrends(props.projectId, dimension);
});

onMounted(() => {
    loadData();
    subscribeRealtime();
});

onUnmounted(() => {
    unsubscribeRealtime();
});
</script>

<template>
    <div data-testid="dashboard-health-panel">
        <div
            v-if="health.loading && !hasSummaryData"
            class="flex items-center justify-center py-10"
            data-testid="health-loading"
        >
            <BaseSpinner size="md" />
        </div>

        <BaseEmptyState
            v-else-if="!hasSummaryData"
            data-testid="health-empty-state"
        >
            <template #title>
                Health analysis has not run yet
            </template>
            <template #description>
                Run `health:analyze` or wait for the daily scheduler to record the first health snapshot.
            </template>
        </BaseEmptyState>

        <div v-else class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <button
                    type="button"
                    class="text-left"
                    data-testid="health-metric-coverage"
                    @click="selectDimension('coverage')"
                >
                    <BaseCard>
                        <p class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Coverage
                        </p>
                        <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ scoreLabel(health.summary?.coverage.score ?? null) }}
                        </p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ trendArrow(health.summary?.coverage.trend_direction ?? 'stable') }} {{ lastCheckedLabel(health.summary?.coverage.last_checked_at ?? null) }}
                        </p>
                    </BaseCard>
                </button>

                <button
                    type="button"
                    class="text-left"
                    data-testid="health-metric-dependency"
                    @click="selectDimension('dependency')"
                >
                    <BaseCard>
                        <p class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Dependencies
                        </p>
                        <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ scoreLabel(health.summary?.dependency.score ?? null) }}
                        </p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ trendArrow(health.summary?.dependency.trend_direction ?? 'stable') }} {{ lastCheckedLabel(health.summary?.dependency.last_checked_at ?? null) }}
                        </p>
                    </BaseCard>
                </button>

                <button
                    type="button"
                    class="text-left"
                    data-testid="health-metric-complexity"
                    @click="selectDimension('complexity')"
                >
                    <BaseCard>
                        <p class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Complexity
                        </p>
                        <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ scoreLabel(health.summary?.complexity.score ?? null) }}
                        </p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ trendArrow(health.summary?.complexity.trend_direction ?? 'stable') }} {{ lastCheckedLabel(health.summary?.complexity.last_checked_at ?? null) }}
                        </p>
                    </BaseCard>
                </button>
            </div>

            <BaseCard>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-3">
                    Trend: {{ health.selectedDimension }}
                </h3>
                <HealthTrendChart
                    :data="health.trends"
                    :warning-threshold="selectedThresholds.warning"
                    :critical-threshold="selectedThresholds.critical"
                />
            </BaseCard>

            <div class="space-y-3">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                    Active Health Alerts
                </h3>

                <BaseEmptyState v-if="health.alerts.length === 0" data-testid="health-alerts-empty">
                    <template #description>
                        No active health alerts.
                    </template>
                </BaseEmptyState>

                <div v-else class="space-y-3" data-testid="health-alerts-list">
                    <HealthAlertCard
                        v-for="alert in health.alerts"
                        :key="alert.id"
                        :alert="alert"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
