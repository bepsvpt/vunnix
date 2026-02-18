<script setup lang="ts">
import { computed, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';
import BaseButton from './ui/BaseButton.vue';

const emit = defineEmits<{
    create: [projectId: number];
    close: [];
}>();
const auth = useAuthStore();

const selectedProjectId = ref<number | null>(null);
const creating = ref(false);
const error = ref<string | null>(null);

const chatProjects = computed(() =>
    auth.projects.filter(p => p.permissions.includes('chat.access')),
);

function onSubmit() {
    if (!selectedProjectId.value || creating.value)
        return;
    creating.value = true;
    error.value = null;
    emit('create', selectedProjectId.value);
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="emit('close')">
        <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-4">
                New Conversation
            </h2>

            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Select a project
            </label>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-2">
                This scopes the AI's initial context to the selected project's repository.
            </p>
            <select
                v-model="selectedProjectId"
                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-zinc-400 dark:focus:ring-zinc-600"
            >
                <option :value="null" disabled>
                    Choose a project...
                </option>
                <option
                    v-for="project in chatProjects"
                    :key="project.id"
                    :value="project.id"
                >
                    {{ project.name }}
                </option>
            </select>

            <div v-if="chatProjects.length === 0" class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                You don't have chat access to any projects.
            </div>

            <div v-if="error" class="mt-2 text-xs text-red-600 dark:text-red-400">
                {{ error }}
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <BaseButton
                    variant="secondary"
                    @click="emit('close')"
                >
                    Cancel
                </BaseButton>
                <BaseButton
                    variant="primary"
                    :disabled="!selectedProjectId || creating"
                    @click="onSubmit"
                >
                    {{ creating ? 'Creating...' : 'Start Conversation' }}
                </BaseButton>
            </div>
        </div>
    </div>
</template>
