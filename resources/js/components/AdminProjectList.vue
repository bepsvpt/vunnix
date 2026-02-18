<script setup lang="ts">
import { ref } from 'vue';
import { useAdminStore } from '@/stores/admin';
import BaseBadge from './ui/BaseBadge.vue';
import BaseButton from './ui/BaseButton.vue';
import BaseCard from './ui/BaseCard.vue';
import BaseEmptyState from './ui/BaseEmptyState.vue';
import BaseSpinner from './ui/BaseSpinner.vue';

const emit = defineEmits<{
    configure: [payload: { id: number; name: string }];
}>();

const admin = useAdminStore();
const actionInProgress = ref<number | null>(null);
const actionError = ref<string | null>(null);
const actionWarnings = ref<string[]>([]);

async function handleEnable(projectId: number) {
    actionInProgress.value = projectId;
    actionError.value = null;
    actionWarnings.value = [];

    const result = await admin.enableProject(projectId);

    if (!result.success) {
        actionError.value = result.error ?? null;
    } else if (result.warnings?.length) {
        actionWarnings.value = result.warnings;
    }

    actionInProgress.value = null;
}

async function handleDisable(projectId: number) {
    if (!confirm('Disable this project? The webhook will be removed, but all data will be preserved.')) {
        return;
    }

    actionInProgress.value = projectId;
    actionError.value = null;
    actionWarnings.value = [];

    const result = await admin.disableProject(projectId);

    if (!result.success) {
        actionError.value = result.error ?? null;
    }

    actionInProgress.value = null;
}
</script>

<template>
    <div>
        <!-- Error banner -->
        <div v-if="actionError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="action-error">
            {{ actionError }}
        </div>

        <!-- Warnings banner -->
        <div v-if="actionWarnings.length" class="mb-4 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400" data-testid="action-warnings">
            <p v-for="(warning, i) in actionWarnings" :key="i">
                {{ warning }}
            </p>
        </div>

        <!-- Loading state -->
        <div v-if="admin.loading" class="py-8 text-center">
            <BaseSpinner size="md" class="mx-auto" />
            <p class="mt-2 text-sm text-zinc-500">
                Loading projects...
            </p>
        </div>

        <!-- Empty state -->
        <BaseEmptyState v-else-if="admin.projects.length === 0">
            <template #icon>
                🏗️
            </template>
            <template #title>
                No projects found
            </template>
            <template #description>
                Projects appear here after users log in via GitLab OAuth.
            </template>
        </BaseEmptyState>

        <!-- Project list -->
        <div v-else class="space-y-3">
            <BaseCard
                v-for="project in admin.projects"
                :key="project.id"
                padded
                :data-testid="`project-row-${project.id}`"
            >
                <div class="flex items-center justify-between">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3">
                            <h3 class="text-sm font-medium truncate">
                                {{ project.name }}
                            </h3>
                            <BaseBadge
                                :data-testid="`project-status-${project.id}`"
                                :variant="project.enabled ? 'success' : 'neutral'"
                            >
                                {{ project.enabled ? 'Enabled' : 'Disabled' }}
                            </BaseBadge>
                            <BaseBadge
                                v-if="project.enabled && project.webhook_configured"
                                variant="info"
                            >
                                Webhook active
                            </BaseBadge>
                        </div>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            GitLab #{{ project.gitlab_project_id }}
                            <span v-if="project.slug"> &middot; {{ project.slug }}</span>
                        </p>
                        <div v-if="project.enabled" class="mt-2 flex gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ project.recent_task_count }} tasks (7d)</span>
                            <span>{{ project.active_conversation_count }} active conversations</span>
                        </div>
                    </div>

                    <div class="ml-4 flex-shrink-0 flex gap-2">
                        <BaseButton
                            v-if="!project.enabled"
                            variant="primary"
                            size="sm"
                            :loading="actionInProgress === project.id"
                            :data-testid="`enable-btn-${project.id}`"
                            @click="handleEnable(project.id)"
                        >
                            {{ actionInProgress === project.id ? 'Enabling...' : 'Enable' }}
                        </BaseButton>
                        <template v-else>
                            <BaseButton
                                variant="secondary"
                                size="sm"
                                :data-testid="`configure-btn-${project.id}`"
                                @click="emit('configure', { id: project.id, name: project.name })"
                            >
                                Configure
                            </BaseButton>
                            <BaseButton
                                variant="secondary"
                                size="sm"
                                :loading="actionInProgress === project.id"
                                :data-testid="`disable-btn-${project.id}`"
                                @click="handleDisable(project.id)"
                            >
                                {{ actionInProgress === project.id ? 'Disabling...' : 'Disable' }}
                            </BaseButton>
                        </template>
                    </div>
                </div>
            </BaseCard>
        </div>
    </div>
</template>
