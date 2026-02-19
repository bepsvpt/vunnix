import type { HealthAlert, HealthDimension, HealthSnapshot, HealthSummary } from '@/types';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useProjectHealth } from '@/composables/useProjectHealth';

interface DateRange {
    from?: string;
    to?: string;
}

export const useHealthStore = defineStore('health', () => {
    const summary = ref<HealthSummary | null>(null);
    const trends = ref<HealthSnapshot[]>([]);
    const alerts = ref<HealthAlert[]>([]);
    const selectedDimension = ref<HealthDimension>('coverage');

    const loading = ref(false);
    const summaryLoading = ref(false);
    const trendsLoading = ref(false);
    const alertsLoading = ref(false);

    async function fetchSummary(projectId: number): Promise<void> {
        summaryLoading.value = true;
        try {
            summary.value = await useProjectHealth(projectId).fetchSummary();
        } catch {
            summary.value = null;
        } finally {
            summaryLoading.value = false;
        }
    }

    async function fetchTrends(projectId: number, dimension: HealthDimension, range: DateRange = {}): Promise<void> {
        trendsLoading.value = true;
        selectedDimension.value = dimension;

        try {
            trends.value = await useProjectHealth(projectId).fetchTrends(dimension, range);
        } catch {
            trends.value = [];
        } finally {
            trendsLoading.value = false;
        }
    }

    async function fetchAlerts(projectId: number): Promise<void> {
        alertsLoading.value = true;
        try {
            const response = await useProjectHealth(projectId).fetchAlerts();
            alerts.value = response.data;
        } catch {
            alerts.value = [];
        } finally {
            alertsLoading.value = false;
        }
    }

    function applyRealtimeSnapshot(event: { dimension: HealthDimension; score: number; trend_direction: 'up' | 'down' | 'stable'; created_at: string | null }): void {
        if (summary.value === null) {
            return;
        }

        summary.value[event.dimension] = {
            score: event.score,
            trend_direction: event.trend_direction,
            last_checked_at: event.created_at,
        };
    }

    function $reset(): void {
        summary.value = null;
        trends.value = [];
        alerts.value = [];
        selectedDimension.value = 'coverage';
        loading.value = false;
        summaryLoading.value = false;
        trendsLoading.value = false;
        alertsLoading.value = false;
    }

    return {
        summary,
        trends,
        alerts,
        selectedDimension,
        loading,
        summaryLoading,
        trendsLoading,
        alertsLoading,
        fetchSummary,
        fetchTrends,
        fetchAlerts,
        applyRealtimeSnapshot,
        $reset,
    };
});
