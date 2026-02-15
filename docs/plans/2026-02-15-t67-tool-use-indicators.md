# T67: Chat Page — Tool-Use Activity Indicators

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Show real-time step indicators during Conversation Engine tool calls ("🔍 Browsing src/services/…", "📄 Reading PaymentService.php…") in the chat UI, streamed via SSE.

**Architecture:** The backend already emits `tool_call` and `tool_result` SSE events via the Laravel AI SDK's `StreamableAgentResponse`. The frontend currently ignores these events. We add reactive state in the conversations Pinia store (`activeToolCalls` array), handle `tool_call`/`tool_result` events in `streamMessage()`, create a `ToolUseIndicators.vue` component that maps tool names to emoji+description, wire it into `MessageThread.vue` above the streaming bubble, and write Vitest tests for both store and component.

**Tech Stack:** Vue 3 (Composition API, `<script setup>`), Pinia, Vitest + Vue Test Utils, Tailwind CSS

---

### Task 1: Add tool-call state to conversations store

**Files:**
- Modify: `resources/js/stores/conversations.js`

**Step 1: Add `activeToolCalls` ref**

Add after the `streamingContent` ref (line 29):

```javascript
const activeToolCalls = ref([]);
```

Each entry: `{ id: string, tool: string, input: object }`

**Step 2: Handle `tool_call` and `tool_result` events in `streamMessage`**

Inside the `onEvent` callback (line 198), add handlers before the closing brace:

```javascript
onEvent(event) {
    if (event.type === 'text_delta' && event.delta) {
        accumulated += event.delta;
        streamingContent.value = accumulated;
    }
    if (event.type === 'tool_call') {
        activeToolCalls.value.push({
            id: event.id || `tool-${Date.now()}`,
            tool: event.tool,
            input: event.input || {},
        });
    }
    if (event.type === 'tool_result') {
        activeToolCalls.value = activeToolCalls.value.filter(
            (tc) => tc.tool !== event.tool
        );
    }
},
```

**Step 3: Clear `activeToolCalls` in `onDone` and on reset**

In `onDone()` callback, add: `activeToolCalls.value = [];`

In `$reset()`, add: `activeToolCalls.value = [];`

Clear at the start of `streamMessage()` alongside `streamingContent.value = ''`: `activeToolCalls.value = [];`

**Step 4: Export `activeToolCalls` from the store**

Add `activeToolCalls,` to the return object.

**Step 5: Run vitest**

```bash
npx vitest run resources/js/stores/conversations.test.js
```

Expected: All existing tests pass (new state doesn't break anything).

---

### Task 2: Write store tests for tool-call handling

**Files:**
- Modify: `resources/js/stores/conversations.test.js`

**Step 1: Add tests to the `streamMessage` describe block**

Inside `describe('streamMessage', ...)`, add these tests:

```javascript
it('tracks active tool calls from tool_call events', async () => {
    const events = [
        { type: 'stream_start' },
        { type: 'tool_call', tool: 'ReadFile', input: { file_path: 'src/Auth.php' } },
        { type: 'tool_result', tool: 'ReadFile', output: '<?php ...' },
        { type: 'text_start' },
        { type: 'text_delta', delta: 'Here is the file' },
        { type: 'text_end' },
        { type: 'stream_end' },
        '[DONE]',
    ];
    vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

    const store = useConversationsStore();
    store.selectedId = 'conv-1';
    store.messages = [];

    await store.streamMessage('Show me Auth.php');

    // After stream completes, activeToolCalls should be cleared
    expect(store.activeToolCalls).toEqual([]);
});

it('adds tool_call to activeToolCalls during streaming', async () => {
    let controller;
    const stream = new ReadableStream({
        start(c) { controller = c; },
    });
    const encoder = new TextEncoder();

    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(
        new Response(stream, {
            status: 200,
            headers: { 'Content-Type': 'text/event-stream' },
        })
    )));

    const store = useConversationsStore();
    store.selectedId = 'conv-1';
    store.messages = [];

    const promise = store.streamMessage('Test');
    await vi.waitFor(() => expect(store.streaming).toBe(true));

    controller.enqueue(encoder.encode('data: {"type":"stream_start"}\n\n'));
    controller.enqueue(encoder.encode('data: {"type":"tool_call","tool":"BrowseRepoTree","input":{"project_id":1,"path":"src/"}}\n\n'));

    await vi.waitFor(() => expect(store.activeToolCalls).toHaveLength(1));
    expect(store.activeToolCalls[0].tool).toBe('BrowseRepoTree');
    expect(store.activeToolCalls[0].input.path).toBe('src/');

    controller.enqueue(encoder.encode('data: {"type":"tool_result","tool":"BrowseRepoTree","output":"file1.php\\nfile2.php"}\n\n'));

    await vi.waitFor(() => expect(store.activeToolCalls).toHaveLength(0));

    controller.enqueue(encoder.encode('data: {"type":"stream_end"}\n\ndata: [DONE]\n\n'));
    controller.close();
    await promise;
});

it('clears activeToolCalls when stream completes', async () => {
    const events = [
        { type: 'stream_start' },
        { type: 'tool_call', tool: 'SearchCode', input: { query: 'processPayment' } },
        // Note: no tool_result — simulates edge case
        { type: 'stream_end' },
        '[DONE]',
    ];
    vi.stubGlobal('fetch', vi.fn(() => mockSSEFetch(events)));

    const store = useConversationsStore();
    store.selectedId = 'conv-1';
    store.messages = [];

    await store.streamMessage('Test');

    expect(store.activeToolCalls).toEqual([]);
});
```

**Step 2: Run tests**

```bash
npx vitest run resources/js/stores/conversations.test.js
```

Expected: All tests pass including the 3 new ones.

---

### Task 3: Create ToolUseIndicators component

**Files:**
- Create: `resources/js/components/ToolUseIndicators.vue`

**Step 1: Create the component**

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
    toolCalls: { type: Array, required: true },
});

const TOOL_DISPLAY = {
    BrowseRepoTree: { emoji: '🔍', verb: 'Browsing' },
    ReadFile: { emoji: '📄', verb: 'Reading' },
    SearchCode: { emoji: '🔎', verb: 'Searching for' },
    ListIssues: { emoji: '📋', verb: 'Listing issues' },
    ReadIssue: { emoji: '📋', verb: 'Reading issue' },
    ListMergeRequests: { emoji: '🔀', verb: 'Listing merge requests' },
    ReadMergeRequest: { emoji: '🔀', verb: 'Reading merge request' },
    ReadMRDiff: { emoji: '🔀', verb: 'Reading MR diff' },
    DispatchAction: { emoji: '🚀', verb: 'Dispatching action' },
};

const indicators = computed(() =>
    props.toolCalls.map((tc) => {
        const display = TOOL_DISPLAY[tc.tool] || { emoji: '⚙️', verb: tc.tool };
        const context = formatContext(tc.tool, tc.input);
        return {
            id: tc.id,
            emoji: display.emoji,
            text: context ? `${display.verb} ${context}` : `${display.verb}…`,
        };
    })
);

function formatContext(tool, input) {
    if (!input) return '';
    switch (tool) {
        case 'BrowseRepoTree':
            return input.path ? `${input.path}…` : '';
        case 'ReadFile':
            return input.file_path ? `${input.file_path.split('/').pop()}…` : '';
        case 'SearchCode':
            return input.query ? `"${input.query}" across repo…` : '';
        case 'ListIssues':
            return input.state ? `(${input.state})…` : '';
        case 'ReadIssue':
            return input.issue_iid ? `#${input.issue_iid}…` : '';
        case 'ReadMergeRequest':
            return input.merge_request_iid ? `!${input.merge_request_iid}…` : '';
        case 'ReadMRDiff':
            return input.merge_request_iid ? `!${input.merge_request_iid}…` : '';
        default:
            return '';
    }
}
</script>

<template>
  <div
    v-if="indicators.length > 0"
    data-testid="tool-use-indicators"
    class="flex w-full justify-start"
  >
    <div class="max-w-[80%] space-y-1">
      <div
        v-for="indicator in indicators"
        :key="indicator.id"
        class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-zinc-800/50 animate-pulse"
      >
        <span class="shrink-0">{{ indicator.emoji }}</span>
        <span class="truncate">{{ indicator.text }}</span>
      </div>
    </div>
  </div>
</template>
```

**Step 2: Run vitest to ensure no import errors**

```bash
npx vitest run resources/js/components/MessageThread.test.js
```

Expected: Existing tests still pass (component created but not yet wired in).

---

### Task 4: Write ToolUseIndicators component tests

**Files:**
- Create: `resources/js/components/ToolUseIndicators.test.js`

**Step 1: Write the test file**

```javascript
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ToolUseIndicators from './ToolUseIndicators.vue';

function mountIndicators(toolCalls = []) {
    return mount(ToolUseIndicators, {
        props: { toolCalls },
    });
}

describe('ToolUseIndicators', () => {
    it('renders nothing when toolCalls is empty', () => {
        const wrapper = mountIndicators([]);
        expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(false);
    });

    it('renders indicator for BrowseRepoTree with path', () => {
        const wrapper = mountIndicators([
            { id: 'tc-1', tool: 'BrowseRepoTree', input: { path: 'src/services/' } },
        ]);
        expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('🔍');
        expect(wrapper.text()).toContain('Browsing');
        expect(wrapper.text()).toContain('src/services/');
    });

    it('renders indicator for ReadFile with filename', () => {
        const wrapper = mountIndicators([
            { id: 'tc-2', tool: 'ReadFile', input: { file_path: 'src/services/PaymentService.php' } },
        ]);
        expect(wrapper.text()).toContain('📄');
        expect(wrapper.text()).toContain('Reading');
        expect(wrapper.text()).toContain('PaymentService.php');
    });

    it('renders indicator for SearchCode with query', () => {
        const wrapper = mountIndicators([
            { id: 'tc-3', tool: 'SearchCode', input: { query: 'processPayment' } },
        ]);
        expect(wrapper.text()).toContain('🔎');
        expect(wrapper.text()).toContain('Searching for');
        expect(wrapper.text()).toContain('"processPayment"');
    });

    it('renders multiple simultaneous tool calls', () => {
        const wrapper = mountIndicators([
            { id: 'tc-1', tool: 'BrowseRepoTree', input: { path: 'src/' } },
            { id: 'tc-2', tool: 'ReadFile', input: { file_path: 'src/Auth.php' } },
        ]);
        const indicators = wrapper.findAll('[data-testid="tool-use-indicators"] > div > div');
        expect(indicators).toHaveLength(2);
    });

    it('handles unknown tool with fallback display', () => {
        const wrapper = mountIndicators([
            { id: 'tc-4', tool: 'UnknownTool', input: {} },
        ]);
        expect(wrapper.text()).toContain('⚙️');
        expect(wrapper.text()).toContain('UnknownTool');
    });

    it('handles tool_call with no input gracefully', () => {
        const wrapper = mountIndicators([
            { id: 'tc-5', tool: 'ReadFile', input: {} },
        ]);
        expect(wrapper.text()).toContain('📄');
        expect(wrapper.text()).toContain('Reading');
    });

    it('clears when toolCalls becomes empty', async () => {
        const wrapper = mountIndicators([
            { id: 'tc-1', tool: 'ReadFile', input: { file_path: 'test.php' } },
        ]);
        expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(true);

        await wrapper.setProps({ toolCalls: [] });
        expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(false);
    });
});
```

**Step 2: Run tests**

```bash
npx vitest run resources/js/components/ToolUseIndicators.test.js
```

Expected: All 7 tests pass.

---

### Task 5: Wire ToolUseIndicators into MessageThread

**Files:**
- Modify: `resources/js/components/MessageThread.vue`
- Modify: `resources/js/components/MessageThread.test.js`

**Step 1: Import and use ToolUseIndicators in MessageThread.vue**

Add import:
```javascript
import ToolUseIndicators from './ToolUseIndicators.vue';
```

Add the component between the messages list and the streaming bubble (after the `v-for` MessageBubble, before the streaming bubble div):

```html
<!-- Tool-use activity indicators: shows what tools the AI is calling -->
<ToolUseIndicators
    v-if="store.streaming"
    :tool-calls="store.activeToolCalls"
/>
```

Also add a watcher for `store.activeToolCalls` to auto-scroll:

```javascript
watch(() => store.activeToolCalls.length, scrollToBottom);
```

**Step 2: Add MessageThread tests for tool-use indicators**

Add to `MessageThread.test.js` inside the `streaming display` describe block:

```javascript
it('shows tool-use indicators during streaming', () => {
    const store = useConversationsStore();
    store.messages = [
        { id: 'msg-1', role: 'user', content: 'Show me auth', created_at: '2026-02-15T12:00:00+00:00' },
    ];
    store.streaming = true;
    store.activeToolCalls = [
        { id: 'tc-1', tool: 'BrowseRepoTree', input: { path: 'src/' } },
    ];

    const wrapper = mountThread();
    expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Browsing');
});

it('hides tool-use indicators when not streaming', () => {
    const store = useConversationsStore();
    store.messages = [
        { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
    ];
    store.streaming = false;
    store.activeToolCalls = [];

    const wrapper = mountThread();
    expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(false);
});
```

**Step 3: Run all tests**

```bash
npx vitest run resources/js/components/MessageThread.test.js
```

Expected: All existing + 2 new tests pass.

---

### Task 6: Add T67 structural checks to verify_m3.py

**Files:**
- Modify: `verify/verify_m3.py`

**Step 1: Add T67 section before the summary**

Insert before the `# ─── Summary ───` line:

```python
# ============================================================
#  T67: Chat Page — Tool-Use Activity Indicators
# ============================================================
section("T67: Chat Page — Tool-Use Activity Indicators")

# ToolUseIndicators component
checker.check(
    "ToolUseIndicators component exists",
    file_exists("resources/js/components/ToolUseIndicators.vue"),
)
checker.check(
    "ToolUseIndicators has data-testid for testing",
    file_contains("resources/js/components/ToolUseIndicators.vue", 'data-testid="tool-use-indicators"'),
)
checker.check(
    "ToolUseIndicators maps tool names to emoji indicators",
    file_contains("resources/js/components/ToolUseIndicators.vue", "BrowseRepoTree"),
)
checker.check(
    "ToolUseIndicators maps ReadFile tool",
    file_contains("resources/js/components/ToolUseIndicators.vue", "ReadFile"),
)
checker.check(
    "ToolUseIndicators maps SearchCode tool",
    file_contains("resources/js/components/ToolUseIndicators.vue", "SearchCode"),
)
checker.check(
    "ToolUseIndicators formats context from tool input",
    file_contains("resources/js/components/ToolUseIndicators.vue", "formatContext"),
)
checker.check(
    "ToolUseIndicators has animate-pulse for active state",
    file_contains("resources/js/components/ToolUseIndicators.vue", "animate-pulse"),
)

# Store: activeToolCalls state
checker.check(
    "Conversations store has activeToolCalls ref",
    file_contains("resources/js/stores/conversations.js", "const activeToolCalls = ref("),
)
checker.check(
    "Conversations store exports activeToolCalls",
    file_contains("resources/js/stores/conversations.js", "activeToolCalls,"),
)
checker.check(
    "Store handles tool_call SSE events",
    file_contains("resources/js/stores/conversations.js", "event.type === 'tool_call'"),
)
checker.check(
    "Store handles tool_result SSE events",
    file_contains("resources/js/stores/conversations.js", "event.type === 'tool_result'"),
)
checker.check(
    "Store clears activeToolCalls on stream done",
    file_contains("resources/js/stores/conversations.js", "activeToolCalls.value = []"),
)

# MessageThread integration
checker.check(
    "MessageThread imports ToolUseIndicators",
    file_contains("resources/js/components/MessageThread.vue", "ToolUseIndicators"),
)
checker.check(
    "MessageThread renders ToolUseIndicators during streaming",
    file_contains("resources/js/components/MessageThread.vue", "store.activeToolCalls"),
)

# Tests
checker.check(
    "ToolUseIndicators test file exists",
    file_exists("resources/js/components/ToolUseIndicators.test.js"),
)
checker.check(
    "ToolUseIndicators tests cover BrowseRepoTree indicator",
    file_contains("resources/js/components/ToolUseIndicators.test.js", "BrowseRepoTree"),
)
checker.check(
    "ToolUseIndicators tests cover ReadFile indicator",
    file_contains("resources/js/components/ToolUseIndicators.test.js", "ReadFile"),
)
checker.check(
    "ToolUseIndicators tests cover SearchCode indicator",
    file_contains("resources/js/components/ToolUseIndicators.test.js", "SearchCode"),
)
checker.check(
    "ToolUseIndicators tests cover empty state",
    file_contains("resources/js/components/ToolUseIndicators.test.js", "renders nothing when toolCalls is empty"),
)
checker.check(
    "Store tests cover tool_call tracking",
    file_contains("resources/js/stores/conversations.test.js", "tracks active tool calls"),
)
checker.check(
    "Store tests cover tool_call intermediate state",
    file_contains("resources/js/stores/conversations.test.js", "adds tool_call to activeToolCalls"),
)
checker.check(
    "MessageThread tests cover tool-use indicators",
    file_contains("resources/js/components/MessageThread.test.js", "tool-use indicators"),
)
```

**Step 2: Run verification**

```bash
python3 verify/verify_m3.py
```

Expected: All checks pass.

---

### Task 7: Run full verification and commit

**Step 1: Run vitest**

```bash
npx vitest run
```

Expected: All frontend tests pass.

**Step 2: Run verification**

```bash
python3 verify/verify_m3.py
```

Expected: All structural checks pass.

**Step 3: Commit**

```bash
git add resources/js/components/ToolUseIndicators.vue \
      resources/js/components/ToolUseIndicators.test.js \
      resources/js/components/MessageThread.vue \
      resources/js/components/MessageThread.test.js \
      resources/js/stores/conversations.js \
      resources/js/stores/conversations.test.js \
      verify/verify_m3.py
git commit --no-gpg-sign -m "$(cat <<'EOF'
T67: Add tool-use activity indicators to chat page

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```
