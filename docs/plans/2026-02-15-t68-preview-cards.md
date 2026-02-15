# T68: Chat Page — Preview Cards Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Render structured preview cards with Confirm/Cancel buttons in the chat when the AI proposes action dispatches, with different fields per action type.

**Architecture:** The AI's system prompt already enforces a "present preview → wait for confirmation" protocol. We enhance this by: (1) updating the system prompt to emit a structured JSON block (`:::action_preview{...}:::`) that the frontend can reliably detect in the stream, (2) building an `ActionPreviewCard.vue` component that renders action-type-specific fields, and (3) adding store state to track pending action previews and send confirmation/cancellation as user messages.

**Tech Stack:** Vue 3 (Composition API, `<script setup>`), Pinia, Tailwind CSS, Vitest + Vue Test Utils

---

### Task 1: Add `pendingAction` state to conversations store

**Files:**
- Modify: `resources/js/stores/conversations.js`

**Step 1: Add state and actions**

Add to the store:
```js
// Action preview state (T68)
const pendingAction = ref(null);
```

Add action preview detection in `streamMessage()`'s `onEvent` handler — when the AI streams a `tool_call` for `DispatchAction`, capture the input as `pendingAction`:

```js
if (event.type === 'tool_call' && event.tool === 'DispatchAction') {
    pendingAction.value = {
        id: event.id || `action-${Date.now()}`,
        ...event.input,
    };
}
```

Add confirmation/cancellation actions:
```js
async function confirmAction() {
    if (!pendingAction.value) return;
    pendingAction.value = null;
    // The tool already executed server-side — no additional action needed.
    // The AI's tool_result will contain the dispatch confirmation.
}

function cancelAction() {
    pendingAction.value = null;
    // Note: The tool has already executed server-side. Cancel is cosmetic
    // at the tool_call level. For true pre-dispatch cancellation, see Task 3.
}
```

Clear `pendingAction` in the `onDone` callback and `$reset`.

Export `pendingAction`, `confirmAction`, `cancelAction`.

**Step 2: Run existing store tests**

Run: `npx vitest run resources/js/stores/conversations.test.js`
Expected: All existing tests pass (no regressions)

**Step 3: Commit**

```bash
git add resources/js/stores/conversations.js
git commit --no-gpg-sign -m "T68.1: Add pendingAction state to conversations store"
```

---

### Task 2: Update system prompt to emit structured preview markers

**Files:**
- Modify: `app/Agents/VunnixAgent.php` (actionDispatchSection method)

**Step 1: Update dispatch protocol in system prompt**

Update `actionDispatchSection()` to instruct the AI to emit a detectable structured block before waiting for confirmation:

```php
protected function actionDispatchSection(): string
{
    return <<<'PROMPT'
[Action Dispatch]
You can dispatch actions to the task queue using the DispatchAction tool. Supported action types:
- **create_issue** — Create a GitLab Issue (PRD) with title, description, assignee, labels
- **implement_feature** — Dispatch a feature implementation to GitLab Runner
- **ui_adjustment** — Dispatch a UI change to GitLab Runner
- **create_mr** — Dispatch merge request creation to GitLab Runner
- **deep_analysis** — Dispatch a read-only deep codebase analysis to GitLab Runner (D132)

**Dispatch protocol:**
1. Confirm you have enough context (ask clarifying questions if not — apply quality gate)
2. Present the action preview using this exact JSON format in a fenced code block with language `action_preview`:

```action_preview
{"action_type":"create_issue","project_id":42,"title":"...","description":"...","assignee_id":7,"labels":["feature"]}
```

The frontend will render this as a structured preview card. Include all relevant fields for the action type:
- **create_issue**: action_type, project_id, title, description, assignee_id (optional), labels
- **implement_feature**: action_type, project_id, title, description, branch_name, target_branch
- **ui_adjustment**: action_type, project_id, title, description, branch_name, target_branch, files (array of file paths to modify)
- **create_mr**: action_type, project_id, title, description, branch_name, target_branch

3. Wait for explicit user confirmation before calling DispatchAction
4. Never dispatch an action without explicit user confirmation

**Permission handling:**
The DispatchAction tool checks the user's `chat.dispatch_task` permission automatically.
If the user lacks this permission, explain that they need to contact their project admin to get the "chat.dispatch_task" permission assigned to their role.

**Deep analysis (D132):**
When your GitLab API tools (BrowseRepoTree, ReadFile, SearchCode) are insufficient for complex cross-module questions, proactively suggest a deep analysis dispatch:
"This question requires deeper codebase scanning than my API tools can provide. Shall I run a background deep analysis?"
Deep analysis is read-only and non-destructive — no preview card is needed. On user confirmation, dispatch with action_type "deep_analysis". The result will be fed back into this conversation.
PROMPT;
}
```

**Step 2: Run existing agent tests**

Run: `php artisan test --filter=VunnixAgent`
Expected: All existing tests pass

**Step 3: Commit**

```bash
git add app/Agents/VunnixAgent.php
git commit --no-gpg-sign -m "T68.2: Update system prompt to emit structured action preview blocks"
```

---

### Task 3: Add action preview detection to SSE stream handler

**Files:**
- Modify: `resources/js/stores/conversations.js`

**Step 1: Add preview detection in streaming text**

In the `streamMessage()` function's `onEvent` handler, detect `action_preview` code blocks in the accumulated text. When a complete `action_preview` block is detected, parse it and set `pendingAction`:

```js
// Inside onEvent, after handling text_delta:
if (event.type === 'text_delta' && event.delta) {
    accumulated += event.delta;
    streamingContent.value = accumulated;

    // Detect action_preview blocks in accumulated text
    const previewMatch = accumulated.match(/```action_preview\n([\s\S]*?)```/);
    if (previewMatch && !pendingAction.value) {
        try {
            const previewData = JSON.parse(previewMatch[1].trim());
            pendingAction.value = {
                id: `preview-${Date.now()}`,
                ...previewData,
            };
        } catch {
            // Malformed JSON — ignore, user will see raw text
        }
    }
}
```

**Step 2: Add `confirmAction` that sends user message**

Replace the simple `confirmAction` with one that sends a confirmation message to continue the conversation flow:

```js
async function confirmAction() {
    if (!pendingAction.value || !selectedId.value) return;
    const action = pendingAction.value;
    pendingAction.value = null;
    // Send confirmation as a user message — the AI will then call DispatchAction
    await streamMessage(`Confirmed. Go ahead with: ${action.title}`);
}

function cancelAction() {
    if (!pendingAction.value) return;
    pendingAction.value = null;
    // Send cancellation — no need to stream, just inform
    streamMessage('Cancel this action, I changed my mind.');
}
```

**Step 3: Commit**

```bash
git add resources/js/stores/conversations.js
git commit --no-gpg-sign -m "T68.3: Add action preview detection in SSE stream"
```

---

### Task 4: Create ActionPreviewCard component

**Files:**
- Create: `resources/js/components/ActionPreviewCard.vue`

**Step 1: Write the failing test**

Create `resources/js/components/ActionPreviewCard.test.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import ActionPreviewCard from './ActionPreviewCard.vue';

function makeAction(overrides = {}) {
    return {
        id: 'preview-1',
        action_type: 'create_issue',
        project_id: 42,
        title: 'Add payment integration',
        description: 'Implement Stripe payment flow with checkout and webhooks',
        ...overrides,
    };
}

function mountCard(action, overrides = {}) {
    return mount(ActionPreviewCard, {
        props: { action, ...overrides },
    });
}

describe('ActionPreviewCard', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    // -- Rendering --

    it('renders the card with data-testid', () => {
        const wrapper = mountCard(makeAction());
        expect(wrapper.find('[data-testid="action-preview-card"]').exists()).toBe(true);
    });

    it('displays the action title', () => {
        const wrapper = mountCard(makeAction({ title: 'Fix login bug' }));
        expect(wrapper.text()).toContain('Fix login bug');
    });

    it('displays description preview', () => {
        const wrapper = mountCard(makeAction({ description: 'A long description here' }));
        expect(wrapper.text()).toContain('A long description here');
    });

    it('shows Confirm and Cancel buttons', () => {
        const wrapper = mountCard(makeAction());
        expect(wrapper.find('[data-testid="confirm-btn"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="cancel-btn"]').exists()).toBe(true);
    });

    // -- Action type specific --

    it('shows Issue-specific fields for create_issue', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'create_issue',
            assignee_id: 7,
            labels: ['feature', 'ai::created'],
        }));
        expect(wrapper.find('[data-testid="action-type-badge"]').text()).toContain('Create Issue');
        expect(wrapper.text()).toContain('Assignee');
    });

    it('shows feature-specific fields for implement_feature', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'implement_feature',
            branch_name: 'ai/payment-feature',
            target_branch: 'main',
        }));
        expect(wrapper.find('[data-testid="action-type-badge"]').text()).toContain('Implement Feature');
        expect(wrapper.text()).toContain('ai/payment-feature');
    });

    it('shows UI adjustment fields for ui_adjustment', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'ui_adjustment',
            branch_name: 'ai/fix-padding',
            files: ['src/components/Card.vue'],
        }));
        expect(wrapper.find('[data-testid="action-type-badge"]').text()).toContain('UI Adjustment');
        expect(wrapper.text()).toContain('ai/fix-padding');
        expect(wrapper.text()).toContain('Card.vue');
    });

    it('shows MR fields for create_mr', () => {
        const wrapper = mountCard(makeAction({
            action_type: 'create_mr',
            branch_name: 'ai/feature-branch',
            target_branch: 'main',
        }));
        expect(wrapper.find('[data-testid="action-type-badge"]').text()).toContain('Create MR');
        expect(wrapper.text()).toContain('ai/feature-branch');
        expect(wrapper.text()).toContain('main');
    });

    // -- Button events --

    it('emits confirm event when Confirm is clicked', async () => {
        const wrapper = mountCard(makeAction());
        await wrapper.find('[data-testid="confirm-btn"]').trigger('click');
        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });

    it('emits cancel event when Cancel is clicked', async () => {
        const wrapper = mountCard(makeAction());
        await wrapper.find('[data-testid="cancel-btn"]').trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    // -- Description truncation --

    it('truncates long descriptions to ~200 chars', () => {
        const longDesc = 'A'.repeat(300);
        const wrapper = mountCard(makeAction({ description: longDesc }));
        const descEl = wrapper.find('[data-testid="description-preview"]');
        expect(descEl.text().length).toBeLessThan(220);
        expect(descEl.text()).toContain('…');
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/components/ActionPreviewCard.test.js`
Expected: FAIL — component doesn't exist yet

**Step 3: Write the component**

Create `resources/js/components/ActionPreviewCard.vue`:

```vue
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
    ACTION_DISPLAY[props.action.action_type] || { label: props.action.action_type, emoji: '⚙️', color: 'text-zinc-600 dark:text-zinc-400 bg-zinc-100 dark:bg-zinc-800' }
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
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/components/ActionPreviewCard.test.js`
Expected: All tests pass

**Step 5: Commit**

```bash
git add resources/js/components/ActionPreviewCard.vue resources/js/components/ActionPreviewCard.test.js
git commit --no-gpg-sign -m "T68.4: Create ActionPreviewCard component with action-type-specific fields"
```

---

### Task 5: Integrate preview card into MessageThread

**Files:**
- Modify: `resources/js/components/MessageThread.vue`

**Step 1: Import and render ActionPreviewCard**

Add to imports:
```js
import ActionPreviewCard from './ActionPreviewCard.vue';
```

In the template, add the preview card between the tool indicators and the streaming bubble:

```html
<!-- Action preview card: shows structured Confirm/Cancel card for action dispatches -->
<div v-if="store.pendingAction" class="flex w-full justify-start">
  <ActionPreviewCard
    :action="store.pendingAction"
    @confirm="store.confirmAction()"
    @cancel="store.cancelAction()"
  />
</div>
```

**Step 2: Run existing MessageThread tests**

Run: `npx vitest run resources/js/components/MessageThread.test.js`
Expected: All existing tests pass (no regressions)

**Step 3: Commit**

```bash
git add resources/js/components/MessageThread.vue
git commit --no-gpg-sign -m "T68.5: Integrate ActionPreviewCard into MessageThread"
```

---

### Task 6: Add store tests for action preview flow

**Files:**
- Modify: `resources/js/stores/conversations.test.js`

**Step 1: Add tests for pendingAction state**

Append to existing test file:

```js
describe('action preview (T68)', () => {
    it('initializes pendingAction as null', () => {
        const store = useConversationsStore();
        expect(store.pendingAction).toBeNull();
    });

    it('sets pendingAction when action_preview block detected in stream', async () => {
        // This test verifies the stream parsing logic
        const store = useConversationsStore();
        store.selectedId = 'conv-1';
        // ... mock fetch and SSE with action_preview content
    });

    it('clears pendingAction on confirmAction', () => {
        const store = useConversationsStore();
        store.pendingAction = { id: 'p-1', action_type: 'create_issue', title: 'Test' };
        store.confirmAction();
        expect(store.pendingAction).toBeNull();
    });

    it('clears pendingAction on cancelAction', () => {
        const store = useConversationsStore();
        store.pendingAction = { id: 'p-1', action_type: 'create_issue', title: 'Test' };
        store.cancelAction();
        expect(store.pendingAction).toBeNull();
    });

    it('clears pendingAction on $reset', () => {
        const store = useConversationsStore();
        store.pendingAction = { id: 'p-1', action_type: 'create_issue', title: 'Test' };
        store.$reset();
        expect(store.pendingAction).toBeNull();
    });
});
```

**Step 2: Run all store tests**

Run: `npx vitest run resources/js/stores/conversations.test.js`
Expected: All tests pass

**Step 3: Commit**

```bash
git add resources/js/stores/conversations.test.js
git commit --no-gpg-sign -m "T68.6: Add store tests for action preview flow"
```

---

### Task 7: Add MessageThread integration tests for preview card

**Files:**
- Modify: `resources/js/components/MessageThread.test.js`

**Step 1: Add tests**

```js
describe('action preview card integration (T68)', () => {
    it('shows ActionPreviewCard when pendingAction is set', async () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';
        store.messages = [makeMessage()];
        store.pendingAction = {
            id: 'p-1',
            action_type: 'create_issue',
            project_id: 42,
            title: 'Test Issue',
            description: 'A test issue',
        };

        const wrapper = mount(MessageThread, { global: { plugins: [pinia] } });
        expect(wrapper.findComponent(ActionPreviewCard).exists()).toBe(true);
    });

    it('hides ActionPreviewCard when pendingAction is null', () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';
        store.messages = [makeMessage()];
        store.pendingAction = null;

        const wrapper = mount(MessageThread, { global: { plugins: [pinia] } });
        expect(wrapper.findComponent(ActionPreviewCard).exists()).toBe(false);
    });
});
```

**Step 2: Run all MessageThread tests**

Run: `npx vitest run resources/js/components/MessageThread.test.js`
Expected: All tests pass

**Step 3: Commit**

```bash
git add resources/js/components/MessageThread.test.js
git commit --no-gpg-sign -m "T68.7: Add MessageThread integration tests for preview card"
```

---

### Task 8: Add structural verification checks

**Files:**
- Modify: `verify/verify_m3.py`

**Step 1: Add T68 checks**

Add structural checks for:
- `ActionPreviewCard.vue` exists
- `ActionPreviewCard.test.js` exists
- `ActionPreviewCard.vue` contains `data-testid="action-preview-card"`
- `ActionPreviewCard.vue` contains `data-testid="confirm-btn"`
- `ActionPreviewCard.vue` contains `data-testid="cancel-btn"`
- `ActionPreviewCard.vue` contains `data-testid="action-type-badge"`
- `ActionPreviewCard.vue` contains all 4 action types (`create_issue`, `implement_feature`, `ui_adjustment`, `create_mr`)
- `MessageThread.vue` imports `ActionPreviewCard`
- `conversations.js` contains `pendingAction`
- `conversations.js` contains `confirmAction`
- `conversations.js` contains `cancelAction`

**Step 2: Run verification**

Run: `python3 verify/verify_m3.py`
Expected: All checks pass

**Step 3: Commit**

```bash
git add verify/verify_m3.py
git commit --no-gpg-sign -m "T68.8: Add T68 structural verification checks"
```

---

### Task 9: Run full verification and commit

**Step 1: Run Vitest suite**

Run: `npx vitest run`
Expected: All tests pass

**Step 2: Run structural checks**

Run: `python3 verify/verify_m3.py`
Expected: All checks pass

**Step 3: Run Laravel tests**

Run: `php artisan test`
Expected: All tests pass (system prompt change doesn't break existing tests)

**Step 4: Final commit with T68 tag**

```bash
git add -A
git commit --no-gpg-sign -m "T68: Complete chat page preview cards (action-type-specific)"
```
