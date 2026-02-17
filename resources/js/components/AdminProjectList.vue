<script setup>
import { ref } from 'vue';
import { useAdminStore } from '@/stores/admin';

const emit = defineEmits(['configure']);

const admin = useAdminStore();
const actionInProgress = ref(null);
const actionError = ref(null);
const actionWarnings = ref([]);

async function handleEnable(projectId) {
    actionInProgress.value = projectId;
    actionError.value = null;
    actionWarnings.value = [];

    const result = await admin.enableProject(projectId);

    if (!result.success) {
        actionError.value = result.error;
    } else if (result.warnings?.length) {
        actionWarnings.value = result.warnings;
    }

    actionInProgress.value = null;
}

async function handleDisable(projectId) {
    if (!confirm('Disable this project? The webhook will be removed, but all data will be preserved.')) {
        return;
    }

    actionInProgress.value = projectId;
    actionError.value = null;
    actionWarnings.value = [];

    const result = await admin.disableProject(projectId);

    if (!result.success) {
        actionError.value = result.error;
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
        <div v-if="admin.loading" class="py-8 text-center text-zinc-500">
            Loading projects...
        </div>

        <!-- Empty state -->
        <div v-else-if="admin.projects.length === 0" class="py-8 text-center text-zinc-500">
            No projects found. Projects appear here after users log in via GitLab OAuth.
        </div>

        <!-- Project list -->
        <div v-else class="space-y-3">
            <div
                v-for="project in admin.projects"
                :key="project.id"
                class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"
                :data-testid="`project-row-${project.id}`"
            >
                <div class="flex items-center justify-between">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3">
                            <h3 class="text-sm font-medium truncate">
                                {{ project.name }}
                            </h3>
                            <span
                                :data-testid="`project-status-${project.id}`"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="project.enabled
                                    ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                                    : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'"
                            >
                                {{ project.enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                            <span
                                v-if="project.enabled && project.webhook_configured"
                                class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-400"
                            >
                                Webhook active
                            </span>
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
                        <button
                            v-if="!project.enabled"
                            :disabled="actionInProgress === project.id"
                            class="rounded-lg bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                            :data-testid="`enable-btn-${project.id}`"
                            @click="handleEnable(project.id)"
                        >
                            {{ actionInProgress === project.id ? 'Enabling...' : 'Enable' }}
                        </button>
                        <template v-else>
                            <button
                                class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                :data-testid="`configure-btn-${project.id}`"
                                @click="emit('configure', { id: project.id, name: project.name })"
                            >
                                Configure
                            </button>
                            <button
                                :disabled="actionInProgress === project.id"
                                class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800 disabled:opacity-50"
                                :data-testid="`disable-btn-${project.id}`"
                                @click="handleDisable(project.id)"
                            >
                                {{ actionInProgress === project.id ? 'Disabling...' : 'Disable' }}
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
