<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useAdminStore } from '@/stores/admin';
import { useDashboardStore } from '@/stores/dashboard';
import BaseEmptyState from './ui/BaseEmptyState.vue';
import BaseSpinner from './ui/BaseSpinner.vue';

const dashboard = useDashboardStore();
const admin = useAdminStore();

onMounted(() => {
    dashboard.fetchInfrastructureAlerts();
});

const alerts = computed(() => dashboard.infrastructureAlerts);
const status = computed(() => dashboard.infrastructureStatus);

const severityColors: Record<string, string> = {
    critical: 'bg-red-100 dark:bg-red-900/30 border-red-300 dark:border-red-700 text-red-800 dark:text-red-200',
    high: 'bg-orange-100 dark:bg-orange-900/30 border-orange-300 dark:border-orange-700 text-orange-800 dark:text-orange-200',
    warning: 'bg-amber-100 dark:bg-amber-900/30 border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-200',
};

const typeLabels: Record<string, string> = {
    container_health: 'Container Health',
    cpu_usage: 'CPU Usage',
    memory_usage: 'Memory Usage',
    disk_usage: 'Disk Usage',
    queue_depth: 'Queue Depth',
};

const infraTypes = ['container_health', 'cpu_usage', 'memory_usage', 'disk_usage', 'queue_depth'];

const checkStatuses = computed(() => {
    return infraTypes.map((type) => {
        const active = alerts.value.find(a => a.alert_type === type);
        return {
            type,
            label: typeLabels[type] || type,
            status: active ? 'alert' : 'ok',
            severity: active?.severity ?? null,
            message: active?.message ?? null,
        };
    });
});

async function handleAcknowledge(alertId: number) {
    await admin.acknowledgeInfrastructureAlert(alertId);
    dashboard.fetchInfrastructureAlerts();
}
</script>

<template>
    <div data-testid="dashboard-infrastructure">
        <!-- Overall status banner -->
        <div
            v-if="status"
            data-testid="infra-status-banner"
            class="rounded-[var(--radius-card)] border p-4 mb-6"
            :class="status.overall_status === 'healthy'
                ? 'border-green-300 dark:border-green-700 bg-green-50 dark:bg-green-900/20'
                : 'border-orange-300 dark:border-orange-700 bg-orange-50 dark:bg-orange-900/20'"
        >
            <div class="flex items-center gap-2">
                <span
                    class="inline-block w-2.5 h-2.5 rounded-full"
                    :class="status.overall_status === 'healthy' ? 'bg-green-500' : 'bg-orange-500'"
                />
                <span
                    class="text-sm font-medium" :class="status.overall_status === 'healthy'
                        ? 'text-green-800 dark:text-green-200'
                        : 'text-orange-800 dark:text-orange-200'"
                >
                    {{ status.overall_status === 'healthy' ? 'All Systems Healthy' : `${status.active_alerts_count} Active Alert${status.active_alerts_count !== 1 ? 's' : ''}` }}
                </span>
            </div>
        </div>

        <!-- System checks grid -->
        <div class="mb-6">
            <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                System Checks
            </h3>
            <div class="grid grid-cols-5 gap-3" data-testid="infra-checks-grid">
                <div
                    v-for="check in checkStatuses"
                    :key="check.type"
                    :data-testid="`infra-check-${check.type}`"
                    class="rounded-[var(--radius-card)] border p-3 text-center"
                    :class="check.status === 'ok'
                        ? 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800'
                        : 'border-orange-300 dark:border-orange-700 bg-orange-50 dark:bg-orange-900/20'"
                >
                    <span
                        class="inline-block w-2 h-2 rounded-full mb-2"
                        :class="check.status === 'ok' ? 'bg-green-500' : 'bg-orange-500'"
                    />
                    <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                        {{ check.label }}
                    </p>
                    <p
                        class="text-xs mt-0.5" :class="check.status === 'ok'
                            ? 'text-green-600 dark:text-green-400'
                            : 'text-orange-600 dark:text-orange-400'"
                    >
                        {{ check.status === 'ok' ? 'OK' : 'Alert' }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Active alerts list -->
        <div v-if="alerts.length > 0" data-testid="infra-alerts">
            <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                Active Alerts
            </h3>
            <div class="space-y-2">
                <div
                    v-for="alert in alerts"
                    :key="alert.id"
                    :data-testid="`infra-alert-${alert.id}`"
                    class="rounded-[var(--radius-card)] border p-3 flex items-start justify-between" :class="[severityColors[alert.severity] || severityColors.warning]"
                >
                    <div>
                        <span class="text-xs font-semibold uppercase">{{ typeLabels[alert.alert_type] || alert.alert_type }}</span>
                        <p class="text-sm mt-0.5">
                            {{ alert.message }}
                        </p>
                        <p class="text-xs opacity-70 mt-1">
                            {{ new Date(alert.created_at).toLocaleString() }}
                        </p>
                    </div>
                    <button
                        data-testid="acknowledge-btn"
                        class="ml-3 flex-shrink-0 text-xs font-medium underline opacity-70 hover:opacity-100"
                        @click="handleAcknowledge(alert.id)"
                    >
                        Dismiss
                    </button>
                </div>
            </div>
        </div>

        <!-- No alerts state -->
        <BaseEmptyState v-else-if="status" data-testid="infra-no-alerts">
            <template #description>
                No active infrastructure alerts.
            </template>
        </BaseEmptyState>

        <!-- Loading state (no status fetched yet) -->
        <div
            v-else
            data-testid="infra-loading"
            class="flex items-center justify-center py-12"
        >
            <BaseSpinner size="md" />
        </div>
    </div>
</template>
