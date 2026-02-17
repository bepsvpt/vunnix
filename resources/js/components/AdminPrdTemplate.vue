<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useAdminStore } from '@/stores/admin';

interface Props {
    projectId: number;
    projectName: string;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    back: [];
}>();

const admin = useAdminStore();
const saving = ref(false);
const saveSuccess = ref(false);
const saveError = ref<string | null>(null);
const templateContent = ref('');
const resetToDefault = ref(false);

onMounted(() => {
    admin.fetchPrdTemplate(props.projectId);
});

// Sync form state when template loads
watch(() => admin.prdTemplate, (data) => {
    if (!data)
        return;
    templateContent.value = (data.template as string) || '';
}, { immediate: true });

function sourceLabel(): string {
    const source = admin.prdTemplate?.source ?? 'default';
    if (source === 'project')
        return 'Project';
    if (source === 'global')
        return 'Global';
    return 'Default';
}

function isOverridden(): boolean {
    return admin.prdTemplate?.source === 'project';
}

async function handleSave() {
    saving.value = true;
    saveSuccess.value = false;
    saveError.value = null;

    const template = resetToDefault.value ? null : templateContent.value;
    const result = await admin.updatePrdTemplate(props.projectId, template as string);
    saving.value = false;

    if (result.success) {
        saveSuccess.value = true;
        resetToDefault.value = false;
        // Update local state from store
        if (admin.prdTemplate) {
            templateContent.value = (admin.prdTemplate.template as string) || '';
        }
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
            <button
                data-testid="back-btn"
                class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                @click="emit('back')"
            >
                &larr; Back
            </button>
            <h2 class="text-lg font-medium">
                {{ projectName }} &mdash; PRD Template
            </h2>
            <span
                data-testid="template-source"
                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                :class="{
                    'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400': isOverridden(),
                    'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400': admin.prdTemplate?.source === 'global',
                    'bg-zinc-50 text-zinc-400 dark:bg-zinc-900 dark:text-zinc-500': admin.prdTemplate?.source === 'default',
                }"
            >
                {{ sourceLabel() }}
            </span>
        </div>

        <!-- Loading state -->
        <div v-if="admin.prdTemplateLoading" class="py-8 text-center text-zinc-500">
            Loading PRD template...
        </div>

        <template v-else-if="admin.prdTemplate">
            <!-- Success banner -->
            <div v-if="saveSuccess" class="mb-4 rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400" data-testid="template-success">
                PRD template saved successfully.
            </div>

            <!-- Error banner -->
            <div v-if="saveError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="template-error">
                {{ saveError }}
            </div>

            <!-- Info about inheritance -->
            <div class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                This template guides the AI when Product Managers plan features via chat.
                The AI fills sections progressively during the conversation. If no project override is set,
                the global template is used. If no global override, the built-in default is used.
            </div>

            <!-- Reset to default checkbox -->
            <div v-if="isOverridden()" class="mb-4 flex items-center gap-2">
                <input
                    v-model="resetToDefault"
                    type="checkbox"
                    data-testid="reset-default-checkbox"
                    class="h-4 w-4 rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600"
                >
                <label class="text-sm text-zinc-600 dark:text-zinc-400">
                    Remove project override (revert to {{ admin.prdTemplate?.source === 'project' ? 'global/default' : 'default' }})
                </label>
            </div>

            <!-- Template editor -->
            <div class="mb-4 rounded-lg border border-zinc-200 dark:border-zinc-700">
                <textarea
                    v-model="templateContent"
                    data-testid="template-editor"
                    :disabled="resetToDefault"
                    rows="20"
                    class="w-full rounded-lg border-0 bg-transparent p-4 font-mono text-sm focus:ring-0 disabled:opacity-50 dark:text-zinc-300"
                    placeholder="Enter your PRD template in Markdown..."
                />
            </div>

            <!-- Save button -->
            <div class="flex items-center gap-3">
                <button
                    data-testid="save-template-btn"
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                    :disabled="saving"
                    @click="handleSave"
                >
                    {{ saving ? 'Saving...' : 'Save Template' }}
                </button>
            </div>
        </template>
    </div>
</template>
