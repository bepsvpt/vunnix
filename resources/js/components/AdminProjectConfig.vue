<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useAdminStore } from '@/stores/admin';
import BaseButton from './ui/BaseButton.vue';
import BaseCard from './ui/BaseCard.vue';

interface Props {
    projectId: number;
    projectName: string;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    back: [];
    editTemplate: [];
}>();

const admin = useAdminStore();
const saving = ref(false);
const saveSuccess = ref(false);
const saveError = ref<string | null>(null);

interface SettingDef {
    key: string;
    label: string;
    type: string;
    options?: string[];
    placeholder?: string;
    min?: number;
    max?: number;
}

// Local form state: key → value (only overridden values)
const form = ref<Record<string, unknown>>({});

// Top-level setting groups to render in the UI
const topLevelSettings: SettingDef[] = [
    { key: 'ai_model', label: 'AI Model', type: 'select', options: ['opus', 'sonnet', 'haiku'] },
    { key: 'ai_language', label: 'AI Response Language', type: 'text', placeholder: 'en' },
    { key: 'timeout_minutes', label: 'Task Timeout (minutes)', type: 'number', min: 1, max: 60 },
    { key: 'max_tokens', label: 'Max Tokens', type: 'number', min: 1024, max: 200000 },
];

const codeReviewSettings: SettingDef[] = [
    { key: 'code_review.auto_review', label: 'Auto-review on MR', type: 'checkbox' },
    { key: 'code_review.auto_review_on_push', label: 'Auto-review on push', type: 'checkbox' },
    { key: 'code_review.severity_threshold', label: 'Severity threshold', type: 'select', options: ['info', 'minor', 'major', 'critical'] },
];

const featureDevSettings: SettingDef[] = [
    { key: 'feature_dev.enabled', label: 'Feature dev enabled', type: 'checkbox' },
    { key: 'feature_dev.branch_prefix', label: 'Branch prefix', type: 'text', placeholder: 'ai/' },
    { key: 'feature_dev.auto_create_mr', label: 'Auto-create MR', type: 'checkbox' },
];

const conversationSettings: SettingDef[] = [
    { key: 'conversation.enabled', label: 'Conversation enabled', type: 'checkbox' },
    { key: 'conversation.max_history_messages', label: 'Max history messages', type: 'number', min: 10, max: 500 },
    { key: 'conversation.tool_use_gitlab', label: 'GitLab tool use', type: 'checkbox' },
];

const allSettingGroups = [
    { title: 'AI Configuration', settings: topLevelSettings },
    { title: 'Code Review', settings: codeReviewSettings },
    { title: 'Feature Development', settings: featureDevSettings },
    { title: 'Conversation', settings: conversationSettings },
];

onMounted(() => {
    admin.fetchProjectConfig(props.projectId);
});

// Sync form state when config loads
watch(() => admin.projectConfig, (config) => {
    if (!config)
        return;
    const newForm: Record<string, unknown> = {};
    for (const group of allSettingGroups) {
        for (const setting of group.settings) {
            const effective = (config.effective as Record<string, Record<string, unknown>>)?.[setting.key];
            if (effective) {
                newForm[setting.key] = effective.value;
            }
        }
    }
    form.value = newForm;
}, { immediate: true });

function getSource(key: string): string {
    const effective = (admin.projectConfig?.effective as Record<string, Record<string, unknown>>)?.[key];
    return (effective?.source as string) ?? 'default';
}

function isOverridden(key: string): boolean {
    return getSource(key) === 'project';
}

function sourceLabel(key: string): string {
    const source = getSource(key);
    if (source === 'project')
        return 'Project';
    if (source === 'global')
        return 'Global';
    return 'Default';
}

async function handleSave() {
    saving.value = true;
    saveSuccess.value = false;
    saveError.value = null;

    // Build settings object: include all form values
    const settings: Record<string, unknown> = {};
    for (const group of allSettingGroups) {
        for (const setting of group.settings) {
            const formValue = form.value[setting.key];
            const effective = (admin.projectConfig?.effective as Record<string, Record<string, unknown>>)?.[setting.key];

            if (formValue === null || formValue === undefined) {
                if (effective?.source === 'project') {
                    settings[setting.key] = null;
                }
            } else {
                let castValue: unknown = formValue;
                if (setting.type === 'number') {
                    castValue = Number(formValue);
                } else if (setting.type === 'checkbox') {
                    castValue = Boolean(formValue);
                }
                settings[setting.key] = castValue;
            }
        }
    }

    const result = await admin.updateProjectConfig(props.projectId, settings);
    saving.value = false;

    if (result.success) {
        saveSuccess.value = true;
        setTimeout(() => {
            saveSuccess.value = false;
        }, 3000);
    } else {
        saveError.value = result.error ?? null;
    }
}
</script>

<template>
    <div>
        <!-- Header with back button -->
        <div class="flex items-center gap-3 mb-6">
            <BaseButton
                data-testid="back-btn"
                variant="secondary"
                size="sm"
                @click="emit('back')"
            >
                &larr; Back
            </BaseButton>
            <h2 class="text-lg font-medium">
                {{ projectName }} &mdash; Configuration
            </h2>
            <BaseButton
                data-testid="edit-template-btn"
                variant="secondary"
                size="sm"
                class="ml-auto"
                @click="emit('editTemplate')"
            >
                PRD Template
            </BaseButton>
        </div>

        <!-- Loading state -->
        <div v-if="admin.projectConfigLoading" class="py-8 text-center text-zinc-500">
            Loading configuration...
        </div>

        <template v-else-if="admin.projectConfig">
            <!-- Success banner -->
            <div v-if="saveSuccess" class="mb-4 rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400" data-testid="config-success">
                Configuration saved successfully.
            </div>

            <!-- Error banner -->
            <div v-if="saveError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="config-error">
                {{ saveError }}
            </div>

            <!-- Info about inheritance -->
            <div class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                Values inherit from global settings unless overridden. Overrides are shown with a
                <span class="inline-flex items-center rounded-full bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">Project</span>
                badge.
            </div>

            <!-- Settings groups -->
            <BaseCard v-for="group in allSettingGroups" :key="group.title" class="mb-6">
                <h3 class="text-sm font-medium mb-3">
                    {{ group.title }}
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div
                        v-for="setting in group.settings"
                        :key="setting.key"
                        :data-testid="`config-${setting.key}`"
                    >
                        <div class="flex items-center gap-2 mb-1">
                            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                {{ setting.label }}
                            </label>
                            <span
                                :data-testid="`source-${setting.key}`"
                                class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                :class="{
                                    'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400': isOverridden(setting.key),
                                    'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400': getSource(setting.key) === 'global',
                                    'bg-zinc-50 text-zinc-400 dark:bg-zinc-900 dark:text-zinc-500': getSource(setting.key) === 'default',
                                }"
                            >
                                {{ sourceLabel(setting.key) }}
                            </span>
                        </div>

                        <!-- Select field -->
                        <select
                            v-if="setting.type === 'select'"
                            v-model="form[setting.key]"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        >
                            <option v-for="opt in setting.options" :key="opt" :value="opt">
                                {{ opt.charAt(0).toUpperCase() + opt.slice(1) }}
                            </option>
                        </select>

                        <!-- Text field -->
                        <input
                            v-else-if="setting.type === 'text'"
                            v-model="form[setting.key]"
                            type="text"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                            :placeholder="setting.placeholder"
                        >

                        <!-- Number field -->
                        <input
                            v-else-if="setting.type === 'number'"
                            v-model.number="form[setting.key]"
                            type="number"
                            :min="setting.min"
                            :max="setting.max"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        >

                        <!-- Checkbox field -->
                        <div v-else-if="setting.type === 'checkbox'" class="flex items-center">
                            <input
                                v-model="form[setting.key]"
                                type="checkbox"
                                class="h-4 w-4 rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600"
                            >
                        </div>
                    </div>
                </div>
            </BaseCard>

            <!-- Save button -->
            <div class="flex items-center gap-3">
                <BaseButton
                    data-testid="save-config-btn"
                    variant="primary"
                    :disabled="saving"
                    @click="handleSave"
                >
                    {{ saving ? 'Saving...' : 'Save Configuration' }}
                </BaseButton>
            </div>
        </template>
    </div>
</template>
