<script setup lang="ts">
import type { HealthAlert } from '@/types';
import BaseBadge from '@/components/ui/BaseBadge.vue';
import BaseCard from '@/components/ui/BaseCard.vue';

interface Props {
    alert: HealthAlert;
}

const props = defineProps<Props>();

function badgeVariant(severity: string): 'warning' | 'danger' | 'info' {
    if (severity === 'critical') {
        return 'danger';
    }
    if (severity === 'warning') {
        return 'warning';
    }

    return 'info';
}

function dimensionLabel(type: string): string {
    if (type.includes('coverage')) {
        return 'Coverage';
    }
    if (type.includes('vulnerability') || type.includes('dependency')) {
        return 'Dependencies';
    }
    if (type.includes('complexity')) {
        return 'Complexity';
    }

    return 'Health';
}

function issueUrl(alert: HealthAlert): string | null {
    const context = alert.context;
    if (!context) {
        return null;
    }

    const url = context.gitlab_issue_url;
    return typeof url === 'string' && url !== '' ? url : null;
}

function alertTime(alert: HealthAlert): string {
    const value = alert.detected_at ?? alert.created_at;
    if (!value) {
        return 'Unknown time';
    }

    return new Date(value).toLocaleString();
}
</script>

<template>
    <BaseCard data-testid="health-alert-card">
        <div class="space-y-2">
            <div class="flex items-center gap-2">
                <BaseBadge variant="neutral">
                    {{ dimensionLabel(props.alert.alert_type) }}
                </BaseBadge>
                <BaseBadge :variant="badgeVariant(props.alert.severity)">
                    {{ props.alert.severity }}
                </BaseBadge>
            </div>

            <p class="text-sm text-zinc-800 dark:text-zinc-200" data-testid="health-alert-message">
                {{ props.alert.message }}
            </p>

            <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ alertTime(props.alert) }}
                </p>
                <a
                    v-if="issueUrl(props.alert)"
                    :href="issueUrl(props.alert) ?? undefined"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-xs font-medium text-blue-700 dark:text-blue-300 underline"
                    data-testid="health-alert-issue-link"
                >
                    Open GitLab issue
                </a>
            </div>
        </div>
    </BaseCard>
</template>
