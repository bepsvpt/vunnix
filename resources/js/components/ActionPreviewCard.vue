<script setup>
import { computed } from 'vue';

const props = defineProps({
    action: { type: Object, required: true },
});

const emit = defineEmits(['confirm', 'cancel']);

const ACTION_DISPLAY = {
    create_issue: { label: 'Create Issue', emoji: '📋', color: 'text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/30' },
    implement_feature: { label: 'Implement Feature', emoji: '🚀', color: 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30' },
    ui_adjustment: { label: 'UI Adjustment', emoji: '🎨', color: 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30' },
    create_mr: { label: 'Create MR', emoji: '🔀', color: 'text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/30' },
};

const display = computed(() =>
    ACTION_DISPLAY[props.action.action_type] || {
        label: props.action.action_type,
        emoji: '⚙️',
        color: 'text-zinc-600 dark:text-zinc-400 bg-zinc-100 dark:bg-zinc-800',
    }
);

const descriptionPreview = computed(() => {
    const desc = props.action.description || '';
    if (desc.length <= 200) return desc;
    return desc.slice(0, 197) + '…';
});

const isIssue = computed(() => props.action.action_type === 'create_issue');
const isFeature = computed(() => props.action.action_type === 'implement_feature');
const isUiAdjustment = computed(() => props.action.action_type === 'ui_adjustment');
const isMr = computed(() => props.action.action_type === 'create_mr');
</script>

<template>
  <div
    data-testid="action-preview-card"
    class="w-full max-w-lg rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden"
  >
    <!-- Header -->
    <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 flex items-center gap-2">
      <span
        data-testid="action-type-badge"
        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
        :class="display.color"
      >
        <span>{{ display.emoji }}</span>
        <span>{{ display.label }}</span>
      </span>
    </div>

    <!-- Body -->
    <div class="px-4 py-3 space-y-2">
      <!-- Title -->
      <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
        {{ action.title }}
      </h4>

      <!-- Description -->
      <p
        data-testid="description-preview"
        class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed"
      >
        {{ descriptionPreview }}
      </p>

      <!-- Action-type-specific fields -->
      <div class="text-xs space-y-1 text-zinc-500 dark:text-zinc-400">
        <!-- Issue fields -->
        <template v-if="isIssue">
          <div v-if="action.assignee_id" class="flex items-center gap-1">
            <span class="font-medium text-zinc-700 dark:text-zinc-300">Assignee:</span>
            <span>User #{{ action.assignee_id }}</span>
          </div>
          <div v-if="action.labels?.length" class="flex items-center gap-1 flex-wrap">
            <span class="font-medium text-zinc-700 dark:text-zinc-300">Labels:</span>
            <span
              v-for="label in action.labels"
              :key="label"
              class="inline-block px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400"
            >{{ label }}</span>
          </div>
        </template>

        <!-- Feature fields -->
        <template v-if="isFeature">
          <div v-if="action.branch_name" class="flex items-center gap-1">
            <span class="font-medium text-zinc-700 dark:text-zinc-300">Branch:</span>
            <code class="px-1 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 font-mono text-[11px]">{{ action.branch_name }}</code>
          </div>
          <div v-if="action.target_branch" class="flex items-center gap-1">
            <span class="font-medium text-zinc-700 dark:text-zinc-300">Target:</span>
            <code class="px-1 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 font-mono text-[11px]">{{ action.target_branch }}</code>
          </div>
        </template>

        <!-- UI adjustment fields -->
        <template v-if="isUiAdjustment">
          <div v-if="action.branch_name" class="flex items-center gap-1">
            <span class="font-medium text-zinc-700 dark:text-zinc-300">Branch:</span>
            <code class="px-1 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 font-mono text-[11px]">{{ action.branch_name }}</code>
          </div>
          <div v-if="action.files?.length" class="flex items-start gap-1">
            <span class="font-medium text-zinc-700 dark:text-zinc-300 shrink-0">Files:</span>
            <div class="flex flex-wrap gap-1">
              <code
                v-for="file in action.files"
                :key="file"
                class="px-1 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 font-mono text-[11px]"
              >{{ file.split('/').pop() }}</code>
            </div>
          </div>
        </template>

        <!-- MR fields -->
        <template v-if="isMr">
          <div v-if="action.branch_name" class="flex items-center gap-1">
            <span class="font-medium text-zinc-700 dark:text-zinc-300">Merge:</span>
            <code class="px-1 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 font-mono text-[11px]">{{ action.branch_name }}</code>
            <span>→</span>
            <code class="px-1 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 font-mono text-[11px]">{{ action.target_branch || 'main' }}</code>
          </div>
        </template>

        <!-- Project ID (common) -->
        <div class="flex items-center gap-1">
          <span class="font-medium text-zinc-700 dark:text-zinc-300">Project:</span>
          <span>#{{ action.project_id }}</span>
        </div>
      </div>
    </div>

    <!-- Footer: Confirm / Cancel -->
    <div class="px-4 py-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center gap-2 justify-end">
      <button
        data-testid="cancel-btn"
        class="px-3 py-1.5 text-xs font-medium rounded-lg text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
        @click="emit('cancel')"
      >
        Cancel
      </button>
      <button
        data-testid="confirm-btn"
        class="px-3 py-1.5 text-xs font-medium rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition-colors"
        @click="emit('confirm')"
      >
        Confirm
      </button>
    </div>
  </div>
</template>
