# T65: Chat Page — Message Thread + Markdown Rendering

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build the message thread UI that displays user and AI messages with rich markdown rendering (headings, code blocks with syntax highlighting, tables, lists, bold/italic, links) and a compose input for sending messages.

**Architecture:** Four new Vue components: `MarkdownContent` (thin wrapper around `markdown-it` + `@shikijs/markdown-it` per D138), `MessageBubble` (per-message styling by role), `MessageThread` (scroll container + message list + auto-scroll), and `MessageComposer` (textarea + send button). The existing `conversations.js` Pinia store is extended with message state and actions (`fetchMessages`, `sendMessage`). Shiki is initialized lazily as a module-level singleton — code blocks render as plain `<pre><code>` until Shiki loads (~100ms), then re-render with syntax highlighting.

**Tech Stack:** Vue 3 (Composition API, `<script setup>`), Pinia, `markdown-it` ^14.1.1, `@shikijs/markdown-it` ^3.22.0, `shiki` ^3.22.0, Tailwind CSS 4, Vitest + Vue Test Utils

---

### Task 1: Create MarkdownContent component with tests

**Files:**
- Create: `resources/js/components/MarkdownContent.vue`
- Test: `resources/js/components/MarkdownContent.test.js`

**Step 1: Write the failing test**

Create `resources/js/components/MarkdownContent.test.js`:

```javascript
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import MarkdownContent from './MarkdownContent.vue';

describe('MarkdownContent', () => {
    function mountContent(content) {
        return mount(MarkdownContent, {
            props: { content },
        });
    }

    it('renders heading as <h1>', () => {
        const wrapper = mountContent('# Hello World');
        expect(wrapper.find('h1').text()).toBe('Hello World');
    });

    it('renders bold text', () => {
        const wrapper = mountContent('**bold text**');
        expect(wrapper.find('strong').text()).toBe('bold text');
    });

    it('renders italic text', () => {
        const wrapper = mountContent('*italic text*');
        expect(wrapper.find('em').text()).toBe('italic text');
    });

    it('renders links with target _blank', () => {
        const wrapper = mountContent('[click](https://example.com)');
        const link = wrapper.find('a');
        expect(link.attributes('href')).toBe('https://example.com');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener noreferrer');
    });

    it('renders code blocks as pre > code', () => {
        const wrapper = mountContent('```js\nconst x = 1;\n```');
        expect(wrapper.find('pre').exists()).toBe(true);
        expect(wrapper.find('code').exists()).toBe(true);
    });

    it('renders inline code', () => {
        const wrapper = mountContent('Use `npm install` to install');
        expect(wrapper.find('code').text()).toBe('npm install');
    });

    it('renders unordered lists', () => {
        const wrapper = mountContent('- item one\n- item two');
        expect(wrapper.findAll('li')).toHaveLength(2);
    });

    it('renders tables', () => {
        const md = '| A | B |\n|---|---|\n| 1 | 2 |';
        const wrapper = mountContent(md);
        expect(wrapper.find('table').exists()).toBe(true);
        expect(wrapper.findAll('td')).toHaveLength(2);
    });

    it('renders empty string without errors', () => {
        const wrapper = mountContent('');
        expect(wrapper.find('.markdown-content').exists()).toBe(true);
    });

    it('applies markdown-content class to wrapper', () => {
        const wrapper = mountContent('hello');
        expect(wrapper.find('.markdown-content').exists()).toBe(true);
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/components/MarkdownContent.test.js`
Expected: FAIL — `MarkdownContent` component does not exist yet.

**Step 3: Write the MarkdownContent component**

Create `resources/js/components/MarkdownContent.vue`:

```vue
<script setup>
import { computed } from 'vue';
import MarkdownIt from 'markdown-it';

const props = defineProps({
    content: { type: String, default: '' },
});

const md = new MarkdownIt({
    html: false,
    linkify: true,
    typographer: true,
});

// Open links in new tab with security attributes
const defaultRender = md.renderer.rules.link_open ||
    function (tokens, idx, options, env, self) {
        return self.renderToken(tokens, idx, options);
    };

md.renderer.rules.link_open = function (tokens, idx, options, env, self) {
    tokens[idx].attrSet('target', '_blank');
    tokens[idx].attrSet('rel', 'noopener noreferrer');
    return defaultRender(tokens, idx, options, env, self);
};

const rendered = computed(() => md.render(props.content || ''));
</script>

<template>
  <div class="markdown-content" v-html="rendered" />
</template>
```

Note: Shiki syntax highlighting is added in Task 2 as an enhancement. This base component handles all markdown rendering. The separation makes testing simpler — Shiki is async and needs special handling.

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/components/MarkdownContent.test.js`
Expected: All 10 tests PASS.

**Step 5: Commit**

```bash
git add resources/js/components/MarkdownContent.vue resources/js/components/MarkdownContent.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T65.1: Create MarkdownContent component with markdown-it rendering

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Add Shiki syntax highlighting to MarkdownContent

**Files:**
- Modify: `resources/js/components/MarkdownContent.vue`
- Create: `resources/js/lib/markdown.js` (shared markdown-it instance with Shiki plugin)
- Modify: `resources/js/components/MarkdownContent.test.js`

**Step 1: Create the markdown singleton module**

Create `resources/js/lib/markdown.js`:

```javascript
import MarkdownIt from 'markdown-it';

let md = null;
let shikiReady = false;
let shikiPromise = null;
let onHighlightReady = null;

/**
 * Creates the base markdown-it instance with link security.
 */
function createBaseInstance() {
    const instance = new MarkdownIt({
        html: false,
        linkify: true,
        typographer: true,
    });

    // Open links in new tab with security attributes
    const defaultRender = instance.renderer.rules.link_open ||
        function (tokens, idx, options, env, self) {
            return self.renderToken(tokens, idx, options);
        };

    instance.renderer.rules.link_open = function (tokens, idx, options, env, self) {
        tokens[idx].attrSet('target', '_blank');
        tokens[idx].attrSet('rel', 'noopener noreferrer');
        return defaultRender(tokens, idx, options, env, self);
    };

    return instance;
}

/**
 * Lazily initializes Shiki and attaches it to the markdown-it instance.
 * Returns a promise that resolves when highlighting is ready.
 */
function initShiki() {
    if (shikiPromise) return shikiPromise;

    shikiPromise = import('@shikijs/markdown-it').then(async ({ default: markdownItShiki }) => {
        const plugin = await markdownItShiki({
            themes: {
                light: 'github-light',
                dark: 'github-dark',
            },
        });
        md.use(plugin);
        shikiReady = true;
        if (onHighlightReady) onHighlightReady();
    }).catch(() => {
        // Shiki failed to load — code blocks stay as plain <pre><code>
    });

    return shikiPromise;
}

/**
 * Returns the markdown-it instance (creates if needed).
 * Kicks off async Shiki initialization on first call.
 */
export function getMarkdownRenderer() {
    if (!md) {
        md = createBaseInstance();
        initShiki();
    }
    return md;
}

/**
 * Whether Shiki syntax highlighting is ready.
 */
export function isHighlightReady() {
    return shikiReady;
}

/**
 * Register a callback for when Shiki finishes loading.
 */
export function onHighlightLoaded(callback) {
    if (shikiReady) {
        callback();
        return;
    }
    onHighlightReady = callback;
}

/**
 * Reset for testing — clears the singleton.
 */
export function _resetForTesting() {
    md = null;
    shikiReady = false;
    shikiPromise = null;
    onHighlightReady = null;
}
```

**Step 2: Update MarkdownContent to use the shared singleton**

Replace `resources/js/components/MarkdownContent.vue` with:

```vue
<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { getMarkdownRenderer, isHighlightReady, onHighlightLoaded } from '@/lib/markdown';

const props = defineProps({
    content: { type: String, default: '' },
});

const highlightVersion = ref(0);

function handleHighlightReady() {
    highlightVersion.value++;
}

onMounted(() => {
    if (!isHighlightReady()) {
        onHighlightLoaded(handleHighlightReady);
    }
});

const rendered = computed(() => {
    // Access highlightVersion to trigger re-compute when Shiki loads
    void highlightVersion.value;
    const md = getMarkdownRenderer();
    return md.render(props.content || '');
});
</script>

<template>
  <div class="markdown-content" v-html="rendered" />
</template>
```

**Step 3: Update test to mock the markdown module**

Update `resources/js/components/MarkdownContent.test.js` to mock the lib:

```javascript
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import MarkdownIt from 'markdown-it';
import MarkdownContent from './MarkdownContent.vue';

// Mock the markdown module to avoid async Shiki loading in tests
const testMd = new MarkdownIt({ html: false, linkify: true, typographer: true });

// Add link security rules (same as production)
const defaultRender = testMd.renderer.rules.link_open ||
    function (tokens, idx, options, env, self) {
        return self.renderToken(tokens, idx, options);
    };
testMd.renderer.rules.link_open = function (tokens, idx, options, env, self) {
    tokens[idx].attrSet('target', '_blank');
    tokens[idx].attrSet('rel', 'noopener noreferrer');
    return defaultRender(tokens, idx, options, env, self);
};

vi.mock('@/lib/markdown', () => ({
    getMarkdownRenderer: () => testMd,
    isHighlightReady: () => false,
    onHighlightLoaded: vi.fn(),
}));

describe('MarkdownContent', () => {
    function mountContent(content) {
        return mount(MarkdownContent, {
            props: { content },
        });
    }

    it('renders heading as <h1>', () => {
        const wrapper = mountContent('# Hello World');
        expect(wrapper.find('h1').text()).toBe('Hello World');
    });

    it('renders bold text', () => {
        const wrapper = mountContent('**bold text**');
        expect(wrapper.find('strong').text()).toBe('bold text');
    });

    it('renders italic text', () => {
        const wrapper = mountContent('*italic text*');
        expect(wrapper.find('em').text()).toBe('italic text');
    });

    it('renders links with target _blank', () => {
        const wrapper = mountContent('[click](https://example.com)');
        const link = wrapper.find('a');
        expect(link.attributes('href')).toBe('https://example.com');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener noreferrer');
    });

    it('renders code blocks as pre > code', () => {
        const wrapper = mountContent('```js\nconst x = 1;\n```');
        expect(wrapper.find('pre').exists()).toBe(true);
        expect(wrapper.find('code').exists()).toBe(true);
    });

    it('renders inline code', () => {
        const wrapper = mountContent('Use `npm install` to install');
        expect(wrapper.find('code').text()).toBe('npm install');
    });

    it('renders unordered lists', () => {
        const wrapper = mountContent('- item one\n- item two');
        expect(wrapper.findAll('li')).toHaveLength(2);
    });

    it('renders tables', () => {
        const md = '| A | B |\n|---|---|\n| 1 | 2 |';
        const wrapper = mountContent(md);
        expect(wrapper.find('table').exists()).toBe(true);
        expect(wrapper.findAll('td')).toHaveLength(2);
    });

    it('renders empty string without errors', () => {
        const wrapper = mountContent('');
        expect(wrapper.find('.markdown-content').exists()).toBe(true);
    });

    it('applies markdown-content class to wrapper', () => {
        const wrapper = mountContent('hello');
        expect(wrapper.find('.markdown-content').exists()).toBe(true);
    });
});
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/components/MarkdownContent.test.js`
Expected: All 10 tests PASS.

**Step 5: Commit**

```bash
git add resources/js/lib/markdown.js resources/js/components/MarkdownContent.vue resources/js/components/MarkdownContent.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T65.2: Add Shiki syntax highlighting via lazy singleton (D138)

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Create MessageBubble component with tests

**Files:**
- Create: `resources/js/components/MessageBubble.vue`
- Test: `resources/js/components/MessageBubble.test.js`

**Step 1: Write the failing test**

Create `resources/js/components/MessageBubble.test.js`:

```javascript
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import MessageBubble from './MessageBubble.vue';

// Mock MarkdownContent since it's tested separately
vi.mock('@/lib/markdown', () => ({
    getMarkdownRenderer: () => ({
        render: (content) => `<p>${content}</p>`,
    }),
    isHighlightReady: () => false,
    onHighlightLoaded: vi.fn(),
}));

function makeMessage(overrides = {}) {
    return {
        id: 'msg-1',
        role: 'user',
        content: 'Hello there',
        user_id: 1,
        created_at: '2026-02-15T12:00:00+00:00',
        ...overrides,
    };
}

function mountBubble(message, props = {}) {
    return mount(MessageBubble, {
        props: { message, ...props },
    });
}

describe('MessageBubble', () => {
    it('renders user message with user styling', () => {
        const wrapper = mountBubble(makeMessage({ role: 'user' }));
        expect(wrapper.find('[data-role="user"]').exists()).toBe(true);
    });

    it('renders assistant message with assistant styling', () => {
        const wrapper = mountBubble(makeMessage({ role: 'assistant' }));
        expect(wrapper.find('[data-role="assistant"]').exists()).toBe(true);
    });

    it('displays user message content as plain text', () => {
        const wrapper = mountBubble(makeMessage({ role: 'user', content: 'Hello there' }));
        expect(wrapper.text()).toContain('Hello there');
    });

    it('renders assistant message with MarkdownContent', () => {
        const wrapper = mountBubble(makeMessage({ role: 'assistant', content: '**bold**' }));
        expect(wrapper.findComponent({ name: 'MarkdownContent' }).exists()).toBe(true);
    });

    it('does not use MarkdownContent for user messages', () => {
        const wrapper = mountBubble(makeMessage({ role: 'user' }));
        expect(wrapper.findComponent({ name: 'MarkdownContent' }).exists()).toBe(false);
    });

    it('displays timestamp', () => {
        const wrapper = mountBubble(makeMessage());
        // Should contain some time representation
        expect(wrapper.find('[data-testid="timestamp"]').exists()).toBe(true);
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/components/MessageBubble.test.js`
Expected: FAIL — `MessageBubble` component does not exist yet.

**Step 3: Write the MessageBubble component**

Create `resources/js/components/MessageBubble.vue`:

```vue
<script setup>
import { computed } from 'vue';
import MarkdownContent from './MarkdownContent.vue';

const props = defineProps({
    message: { type: Object, required: true },
});

const isUser = computed(() => props.message.role === 'user');

const formattedTime = computed(() => {
    const date = new Date(props.message.created_at);
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
});
</script>

<template>
  <div
    class="flex w-full"
    :class="isUser ? 'justify-end' : 'justify-start'"
  >
    <div
      class="max-w-[80%] rounded-2xl px-4 py-3"
      :class="isUser
        ? 'bg-blue-600 text-white rounded-br-md'
        : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 rounded-bl-md'"
      :data-role="message.role"
    >
      <!-- User messages: plain text -->
      <p v-if="isUser" class="text-sm whitespace-pre-wrap break-words">{{ message.content }}</p>

      <!-- Assistant messages: rendered markdown -->
      <MarkdownContent v-else :content="message.content" />

      <div
        data-testid="timestamp"
        class="mt-1 text-xs opacity-60"
        :class="isUser ? 'text-right' : 'text-left'"
      >
        {{ formattedTime }}
      </div>
    </div>
  </div>
</template>
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/components/MessageBubble.test.js`
Expected: All 6 tests PASS.

**Step 5: Commit**

```bash
git add resources/js/components/MessageBubble.vue resources/js/components/MessageBubble.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T65.3: Create MessageBubble component with role-based styling

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Create MessageComposer component with tests

**Files:**
- Create: `resources/js/components/MessageComposer.vue`
- Test: `resources/js/components/MessageComposer.test.js`

**Step 1: Write the failing test**

Create `resources/js/components/MessageComposer.test.js`:

```javascript
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import MessageComposer from './MessageComposer.vue';

function mountComposer(props = {}) {
    return mount(MessageComposer, {
        props: { disabled: false, ...props },
    });
}

describe('MessageComposer', () => {
    it('renders a textarea', () => {
        const wrapper = mountComposer();
        expect(wrapper.find('textarea').exists()).toBe(true);
    });

    it('renders a send button', () => {
        const wrapper = mountComposer();
        expect(wrapper.find('button[type="submit"]').exists()).toBe(true);
    });

    it('emits send event with message content on submit', async () => {
        const wrapper = mountComposer();
        await wrapper.find('textarea').setValue('Hello AI');
        await wrapper.find('form').trigger('submit');
        expect(wrapper.emitted('send')).toHaveLength(1);
        expect(wrapper.emitted('send')[0]).toEqual(['Hello AI']);
    });

    it('clears textarea after sending', async () => {
        const wrapper = mountComposer();
        await wrapper.find('textarea').setValue('Hello AI');
        await wrapper.find('form').trigger('submit');
        expect(wrapper.find('textarea').element.value).toBe('');
    });

    it('does not emit send for empty/whitespace input', async () => {
        const wrapper = mountComposer();
        await wrapper.find('textarea').setValue('   ');
        await wrapper.find('form').trigger('submit');
        expect(wrapper.emitted('send')).toBeUndefined();
    });

    it('disables textarea and button when disabled prop is true', () => {
        const wrapper = mountComposer({ disabled: true });
        expect(wrapper.find('textarea').attributes('disabled')).toBeDefined();
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
    });

    it('emits send on Enter without Shift', async () => {
        const wrapper = mountComposer();
        await wrapper.find('textarea').setValue('Hello');
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter', shiftKey: false });
        expect(wrapper.emitted('send')).toHaveLength(1);
    });

    it('does not emit send on Shift+Enter (allows newline)', async () => {
        const wrapper = mountComposer();
        await wrapper.find('textarea').setValue('Hello');
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter', shiftKey: true });
        expect(wrapper.emitted('send')).toBeUndefined();
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/components/MessageComposer.test.js`
Expected: FAIL — `MessageComposer` component does not exist yet.

**Step 3: Write the MessageComposer component**

Create `resources/js/components/MessageComposer.vue`:

```vue
<script setup>
import { ref } from 'vue';

defineProps({
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['send']);
const input = ref('');

function handleSubmit() {
    const trimmed = input.value.trim();
    if (!trimmed) return;
    emit('send', trimmed);
    input.value = '';
}

function handleKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        handleSubmit();
    }
}
</script>

<template>
  <form
    class="flex items-end gap-2 border-t border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4"
    @submit.prevent="handleSubmit"
  >
    <textarea
      v-model="input"
      :disabled="disabled"
      rows="1"
      class="flex-1 resize-none rounded-xl border border-zinc-300 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 px-4 py-2.5 text-sm text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:opacity-50"
      placeholder="Type a message…"
      @keydown="handleKeydown"
    />
    <button
      type="submit"
      :disabled="disabled"
      class="shrink-0 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
    >
      Send
    </button>
  </form>
</template>
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/components/MessageComposer.test.js`
Expected: All 8 tests PASS.

**Step 5: Commit**

```bash
git add resources/js/components/MessageComposer.vue resources/js/components/MessageComposer.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T65.4: Create MessageComposer component with Enter-to-send

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Extend conversations store with message state and actions

**Files:**
- Modify: `resources/js/stores/conversations.js`
- Modify: `resources/js/stores/conversations.test.js`

**Step 1: Write the failing tests**

Append to `resources/js/stores/conversations.test.js` (inside the existing describe block or add a new describe):

```javascript
describe('message actions', () => {
    it('fetchMessages loads messages for a conversation', async () => {
        const messages = [
            { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            { id: 'msg-2', role: 'assistant', content: 'Hi!', created_at: '2026-02-15T12:00:01+00:00' },
        ];
        axios.get.mockResolvedValueOnce({
            data: { data: { id: 1, messages } },
        });

        const store = useConversationsStore();
        await store.fetchMessages(1);

        expect(axios.get).toHaveBeenCalledWith('/api/v1/conversations/1');
        expect(store.messages).toEqual(messages);
    });

    it('fetchMessages sets messagesLoading while fetching', async () => {
        let resolve;
        axios.get.mockReturnValueOnce(new Promise((r) => { resolve = r; }));

        const store = useConversationsStore();
        const promise = store.fetchMessages(1);

        expect(store.messagesLoading).toBe(true);

        resolve({ data: { data: { id: 1, messages: [] } } });
        await promise;

        expect(store.messagesLoading).toBe(false);
    });

    it('fetchMessages sets messagesError on failure', async () => {
        axios.get.mockRejectedValueOnce({
            response: { data: { message: 'Not found' } },
        });

        const store = useConversationsStore();
        await store.fetchMessages(999);

        expect(store.messagesError).toBe('Not found');
        expect(store.messages).toEqual([]);
    });

    it('sendMessage posts and appends user message', async () => {
        const userMessage = {
            id: 'msg-new',
            role: 'user',
            content: 'Hello AI',
            created_at: '2026-02-15T12:05:00+00:00',
        };
        axios.post.mockResolvedValueOnce({
            data: { data: userMessage },
        });

        const store = useConversationsStore();
        store.selectedId = 1;
        store.messages = [];

        await store.sendMessage('Hello AI');

        expect(axios.post).toHaveBeenCalledWith('/api/v1/conversations/1/messages', {
            content: 'Hello AI',
        });
        expect(store.messages).toHaveLength(1);
        expect(store.messages[0].content).toBe('Hello AI');
    });

    it('sendMessage sets sending flag', async () => {
        let resolve;
        axios.post.mockReturnValueOnce(new Promise((r) => { resolve = r; }));

        const store = useConversationsStore();
        store.selectedId = 1;
        const promise = store.sendMessage('Test');

        expect(store.sending).toBe(true);

        resolve({ data: { data: { id: 'msg-1', role: 'user', content: 'Test', created_at: '2026-02-15T12:00:00+00:00' } } });
        await promise;

        expect(store.sending).toBe(false);
    });

    it('sendMessage sets error on failure', async () => {
        axios.post.mockRejectedValueOnce({
            response: { data: { message: 'Validation error' } },
        });

        const store = useConversationsStore();
        store.selectedId = 1;

        await store.sendMessage('Test');

        expect(store.messagesError).toBe('Validation error');
    });

    it('selectConversation triggers fetchMessages', async () => {
        axios.get.mockResolvedValueOnce({
            data: { data: { id: 1, messages: [{ id: 'msg-1', role: 'user', content: 'Hi', created_at: '2026-02-15T12:00:00+00:00' }] } },
        });

        const store = useConversationsStore();
        await store.selectConversation(1);

        expect(store.selectedId).toBe(1);
        expect(axios.get).toHaveBeenCalledWith('/api/v1/conversations/1');
    });

    it('selectConversation with null clears messages', async () => {
        const store = useConversationsStore();
        store.messages = [{ id: 'msg-1' }];
        await store.selectConversation(null);

        expect(store.selectedId).toBeNull();
        expect(store.messages).toEqual([]);
    });

    it('$reset clears message state', () => {
        const store = useConversationsStore();
        store.messages = [{ id: 'msg-1' }];
        store.messagesLoading = true;
        store.messagesError = 'error';
        store.sending = true;

        store.$reset();

        expect(store.messages).toEqual([]);
        expect(store.messagesLoading).toBe(false);
        expect(store.messagesError).toBeNull();
        expect(store.sending).toBe(false);
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/stores/conversations.test.js`
Expected: FAIL — `messages`, `messagesLoading`, `messagesError`, `sending`, `fetchMessages`, `sendMessage` don't exist on the store.

**Step 3: Add message state and actions to conversations store**

Modify `resources/js/stores/conversations.js` to add these refs and functions inside the `defineStore` callback:

Add new refs after `selectedId`:

```javascript
// Message state (for selected conversation)
const messages = ref([]);
const messagesLoading = ref(false);
const messagesError = ref(null);
const sending = ref(false);
```

Add `fetchMessages` action:

```javascript
/**
 * Fetch messages for a conversation by loading its detail.
 */
async function fetchMessages(conversationId) {
    messagesLoading.value = true;
    messagesError.value = null;
    try {
        const response = await axios.get(`/api/v1/conversations/${conversationId}`);
        messages.value = response.data.data.messages || [];
    } catch (err) {
        messagesError.value = err.response?.data?.message || 'Failed to load messages';
        messages.value = [];
    } finally {
        messagesLoading.value = false;
    }
}
```

Add `sendMessage` action:

```javascript
/**
 * Send a user message to the selected conversation.
 */
async function sendMessage(content) {
    if (!selectedId.value) return;
    sending.value = true;
    messagesError.value = null;
    try {
        const response = await axios.post(
            `/api/v1/conversations/${selectedId.value}/messages`,
            { content }
        );
        messages.value.push(response.data.data);
    } catch (err) {
        messagesError.value = err.response?.data?.message || 'Failed to send message';
    } finally {
        sending.value = false;
    }
}
```

Update `selectConversation` to also fetch messages:

```javascript
async function selectConversation(id) {
    selectedId.value = id;
    if (id) {
        await fetchMessages(id);
    } else {
        messages.value = [];
        messagesError.value = null;
    }
}
```

Update `$reset` to include message state:

```javascript
function $reset() {
    conversations.value = [];
    loading.value = false;
    error.value = null;
    nextCursor.value = null;
    hasMore.value = false;
    projectFilter.value = null;
    searchQuery.value = '';
    showArchived.value = false;
    selectedId.value = null;
    messages.value = [];
    messagesLoading.value = false;
    messagesError.value = null;
    sending.value = false;
}
```

Add `messages`, `messagesLoading`, `messagesError`, `sending`, `fetchMessages`, `sendMessage` to the return object.

**Step 4: Run tests to verify they pass**

Run: `npx vitest run resources/js/stores/conversations.test.js`
Expected: All tests PASS (existing + new).

Note: Existing tests that call `selectConversation(id)` may now trigger the `fetchMessages` call. The `axios.get` mock from `beforeEach` (which resolves to empty data) should handle this, but if tests fail, add a mock for the conversations show endpoint:

```javascript
axios.get.mockImplementation((url) => {
    if (url.match(/\/api\/v1\/conversations\/\d+$/)) {
        return Promise.resolve({ data: { data: { id: 1, messages: [] } } });
    }
    return Promise.resolve({ data: { data: [], meta: { next_cursor: null } } });
});
```

**Step 5: Commit**

```bash
git add resources/js/stores/conversations.js resources/js/stores/conversations.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T65.5: Add message state and actions to conversations store

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Create MessageThread component with tests

**Files:**
- Create: `resources/js/components/MessageThread.vue`
- Test: `resources/js/components/MessageThread.test.js`

**Step 1: Write the failing test**

Create `resources/js/components/MessageThread.test.js`:

```javascript
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import axios from 'axios';
import MessageThread from './MessageThread.vue';
import MessageBubble from './MessageBubble.vue';
import MessageComposer from './MessageComposer.vue';
import { useConversationsStore } from '@/stores/conversations';

vi.mock('axios');

// Mock markdown module to avoid Shiki async loading
vi.mock('@/lib/markdown', () => ({
    getMarkdownRenderer: () => ({
        render: (content) => `<p>${content}</p>`,
    }),
    isHighlightReady: () => false,
    onHighlightLoaded: vi.fn(),
}));

let pinia;

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
    vi.restoreAllMocks();
    axios.get.mockResolvedValue({
        data: { data: [], meta: { next_cursor: null } },
    });
});

function mountThread() {
    return mount(MessageThread, {
        global: { plugins: [pinia] },
    });
}

describe('MessageThread', () => {
    it('renders MessageBubble for each message', async () => {
        const store = useConversationsStore();
        store.messages = [
            { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            { id: 'msg-2', role: 'assistant', content: 'Hi there', created_at: '2026-02-15T12:00:01+00:00' },
        ];

        const wrapper = mountThread();
        expect(wrapper.findAllComponents(MessageBubble)).toHaveLength(2);
    });

    it('renders MessageComposer', () => {
        const wrapper = mountThread();
        expect(wrapper.findComponent(MessageComposer).exists()).toBe(true);
    });

    it('shows loading indicator when messagesLoading is true', () => {
        const store = useConversationsStore();
        store.messagesLoading = true;

        const wrapper = mountThread();
        expect(wrapper.find('[data-testid="messages-loading"]').exists()).toBe(true);
    });

    it('shows error message when messagesError is set', () => {
        const store = useConversationsStore();
        store.messagesError = 'Failed to load';

        const wrapper = mountThread();
        expect(wrapper.text()).toContain('Failed to load');
    });

    it('shows empty state when no messages and not loading', () => {
        const store = useConversationsStore();
        store.messages = [];
        store.messagesLoading = false;

        const wrapper = mountThread();
        expect(wrapper.find('[data-testid="empty-thread"]').exists()).toBe(true);
    });

    it('calls sendMessage on composer send event', async () => {
        const store = useConversationsStore();
        store.selectedId = 1;
        axios.post.mockResolvedValueOnce({
            data: { data: { id: 'msg-new', role: 'user', content: 'Test', created_at: '2026-02-15T12:05:00+00:00' } },
        });

        const wrapper = mountThread();
        const composer = wrapper.findComponent(MessageComposer);
        await composer.vm.$emit('send', 'Test');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/api/v1/conversations/1/messages', {
            content: 'Test',
        });
    });

    it('disables composer while sending', () => {
        const store = useConversationsStore();
        store.sending = true;

        const wrapper = mountThread();
        const composer = wrapper.findComponent(MessageComposer);
        expect(composer.props('disabled')).toBe(true);
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/components/MessageThread.test.js`
Expected: FAIL — `MessageThread` component does not exist yet.

**Step 3: Write the MessageThread component**

Create `resources/js/components/MessageThread.vue`:

```vue
<script setup>
import { ref, watch, nextTick } from 'vue';
import { useConversationsStore } from '@/stores/conversations';
import MessageBubble from './MessageBubble.vue';
import MessageComposer from './MessageComposer.vue';

const store = useConversationsStore();
const scrollContainer = ref(null);

async function scrollToBottom() {
    await nextTick();
    if (scrollContainer.value) {
        scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight;
    }
}

// Auto-scroll when messages change
watch(() => store.messages.length, scrollToBottom);

async function handleSend(content) {
    await store.sendMessage(content);
    scrollToBottom();
}
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- Messages area -->
    <div ref="scrollContainer" class="flex-1 overflow-y-auto px-4 py-4">
      <!-- Loading -->
      <div
        v-if="store.messagesLoading"
        data-testid="messages-loading"
        class="flex items-center justify-center h-full"
      >
        <svg class="animate-spin h-6 w-6 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
      </div>

      <!-- Error -->
      <div
        v-else-if="store.messagesError"
        class="flex items-center justify-center h-full"
      >
        <p class="text-sm text-red-500">{{ store.messagesError }}</p>
      </div>

      <!-- Empty state -->
      <div
        v-else-if="store.messages.length === 0"
        data-testid="empty-thread"
        class="flex items-center justify-center h-full"
      >
        <div class="text-center text-zinc-400 dark:text-zinc-500">
          <svg class="w-10 h-10 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>
          <p class="text-sm">Send a message to start the conversation</p>
        </div>
      </div>

      <!-- Messages -->
      <div v-else class="space-y-3 max-w-3xl mx-auto">
        <MessageBubble
          v-for="message in store.messages"
          :key="message.id"
          :message="message"
        />
      </div>
    </div>

    <!-- Composer -->
    <MessageComposer :disabled="store.sending" @send="handleSend" />
  </div>
</template>
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/components/MessageThread.test.js`
Expected: All 7 tests PASS.

**Step 5: Commit**

```bash
git add resources/js/components/MessageThread.vue resources/js/components/MessageThread.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T65.6: Create MessageThread component with scroll, loading, and empty states

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Integrate MessageThread into ChatPage

**Files:**
- Modify: `resources/js/pages/ChatPage.vue`

**Step 1: Update ChatPage to show MessageThread**

Replace `resources/js/pages/ChatPage.vue`:

```vue
<script setup>
import ConversationList from '@/components/ConversationList.vue';
import MessageThread from '@/components/MessageThread.vue';
import { useConversationsStore } from '@/stores/conversations';

const store = useConversationsStore();
</script>

<template>
  <div class="flex h-[calc(100vh-4rem)] -m-4 lg:-m-8">
    <!-- Sidebar: conversation list -->
    <aside class="w-80 flex-shrink-0 border-r border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
      <ConversationList />
    </aside>

    <!-- Main area: message thread or empty state -->
    <main class="flex-1 flex flex-col min-w-0">
      <MessageThread v-if="store.selectedId" />
      <div v-else class="flex-1 flex items-center justify-center">
        <div class="text-center text-zinc-400 dark:text-zinc-500">
          <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>
          <p class="text-sm">Select a conversation to get started</p>
        </div>
      </div>
    </main>
  </div>
</template>
```

**Step 2: Run all frontend tests**

Run: `npx vitest run`
Expected: All tests PASS.

**Step 3: Commit**

```bash
git add resources/js/pages/ChatPage.vue
git commit --no-gpg-sign -m "$(cat <<'EOF'
T65.7: Integrate MessageThread into ChatPage, replacing placeholder

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Add markdown typography styles

**Files:**
- Modify: `resources/css/app.css`

**Step 1: Add markdown-content styles**

Append to `resources/css/app.css`:

```css
/* Markdown content typography for AI chat responses */
.markdown-content {
    @apply text-sm leading-relaxed;
}

.markdown-content h1 {
    @apply text-lg font-bold mt-4 mb-2 first:mt-0;
}

.markdown-content h2 {
    @apply text-base font-bold mt-3 mb-2 first:mt-0;
}

.markdown-content h3 {
    @apply text-sm font-bold mt-3 mb-1 first:mt-0;
}

.markdown-content p {
    @apply my-2 first:mt-0 last:mb-0;
}

.markdown-content ul,
.markdown-content ol {
    @apply my-2 pl-5;
}

.markdown-content ul {
    @apply list-disc;
}

.markdown-content ol {
    @apply list-decimal;
}

.markdown-content li {
    @apply my-0.5;
}

.markdown-content code:not(pre code) {
    @apply px-1.5 py-0.5 rounded bg-zinc-200/60 dark:bg-zinc-700/60 text-[0.85em] font-mono;
}

.markdown-content pre {
    @apply my-2 rounded-lg overflow-x-auto text-xs;
}

.markdown-content pre code {
    @apply block p-3 font-mono;
}

/* Fallback code block styling (before Shiki loads) */
.markdown-content pre:not([class*="shiki"]) {
    @apply bg-zinc-100 dark:bg-zinc-800;
}

.markdown-content table {
    @apply my-2 w-full border-collapse text-xs;
}

.markdown-content th {
    @apply border border-zinc-300 dark:border-zinc-600 px-3 py-1.5 bg-zinc-50 dark:bg-zinc-800 font-semibold text-left;
}

.markdown-content td {
    @apply border border-zinc-300 dark:border-zinc-600 px-3 py-1.5;
}

.markdown-content blockquote {
    @apply my-2 pl-3 border-l-2 border-zinc-300 dark:border-zinc-600 text-zinc-600 dark:text-zinc-400;
}

.markdown-content a {
    @apply text-blue-600 dark:text-blue-400 underline hover:no-underline;
}

.markdown-content hr {
    @apply my-4 border-zinc-200 dark:border-zinc-700;
}
```

**Step 2: Run all frontend tests**

Run: `npx vitest run`
Expected: All tests PASS (CSS doesn't affect test logic).

**Step 3: Commit**

```bash
git add resources/css/app.css
git commit --no-gpg-sign -m "$(cat <<'EOF'
T65.8: Add markdown typography styles for chat responses

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Add T65 checks to verify_m3.py

**Files:**
- Modify: `verify/verify_m3.py`

**Step 1: Add T65 verification checks**

Append the following section before the final summary output at the bottom of `verify/verify_m3.py`:

```python
# ============================================================
#  T65: Chat Page — Message Thread + Markdown Rendering
# ============================================================
section("T65: Chat Page — Message Thread + Markdown Rendering")

# MarkdownContent component
checker.check(
    "MarkdownContent component exists",
    file_exists("resources/js/components/MarkdownContent.vue"),
)
checker.check(
    "MarkdownContent imports markdown renderer",
    file_contains("resources/js/components/MarkdownContent.vue", "getMarkdownRenderer")
    or file_contains("resources/js/components/MarkdownContent.vue", "markdown-it"),
)
checker.check(
    "MarkdownContent uses v-html for rendered output",
    file_contains("resources/js/components/MarkdownContent.vue", "v-html"),
)
checker.check(
    "MarkdownContent test exists",
    file_exists("resources/js/components/MarkdownContent.test.js"),
)

# Markdown singleton module
checker.check(
    "Markdown lib module exists",
    file_exists("resources/js/lib/markdown.js"),
)
checker.check(
    "Markdown lib imports markdown-it",
    file_contains("resources/js/lib/markdown.js", "markdown-it"),
)
checker.check(
    "Markdown lib imports shiki plugin",
    file_contains("resources/js/lib/markdown.js", "@shikijs/markdown-it"),
)
checker.check(
    "Markdown lib sets link target _blank",
    file_contains("resources/js/lib/markdown.js", "_blank"),
)

# MessageBubble component
checker.check(
    "MessageBubble component exists",
    file_exists("resources/js/components/MessageBubble.vue"),
)
checker.check(
    "MessageBubble has role-based styling",
    file_contains("resources/js/components/MessageBubble.vue", "data-role"),
)
checker.check(
    "MessageBubble renders MarkdownContent for assistant",
    file_contains("resources/js/components/MessageBubble.vue", "MarkdownContent"),
)
checker.check(
    "MessageBubble test exists",
    file_exists("resources/js/components/MessageBubble.test.js"),
)

# MessageComposer component
checker.check(
    "MessageComposer component exists",
    file_exists("resources/js/components/MessageComposer.vue"),
)
checker.check(
    "MessageComposer has textarea",
    file_contains("resources/js/components/MessageComposer.vue", "textarea"),
)
checker.check(
    "MessageComposer emits send event",
    file_contains("resources/js/components/MessageComposer.vue", "emit('send'")
    or file_contains("resources/js/components/MessageComposer.vue", 'emit("send"'),
)
checker.check(
    "MessageComposer test exists",
    file_exists("resources/js/components/MessageComposer.test.js"),
)

# MessageThread component
checker.check(
    "MessageThread component exists",
    file_exists("resources/js/components/MessageThread.vue"),
)
checker.check(
    "MessageThread renders MessageBubble",
    file_contains("resources/js/components/MessageThread.vue", "MessageBubble"),
)
checker.check(
    "MessageThread renders MessageComposer",
    file_contains("resources/js/components/MessageThread.vue", "MessageComposer"),
)
checker.check(
    "MessageThread has scroll container",
    file_contains("resources/js/components/MessageThread.vue", "overflow-y-auto"),
)
checker.check(
    "MessageThread test exists",
    file_exists("resources/js/components/MessageThread.test.js"),
)

# Store integration
checker.check(
    "Conversations store has messages state",
    file_contains("resources/js/stores/conversations.js", "messages"),
)
checker.check(
    "Conversations store has fetchMessages action",
    file_contains("resources/js/stores/conversations.js", "fetchMessages"),
)
checker.check(
    "Conversations store has sendMessage action",
    file_contains("resources/js/stores/conversations.js", "sendMessage"),
)

# ChatPage integration
checker.check(
    "ChatPage imports MessageThread",
    file_contains("resources/js/pages/ChatPage.vue", "MessageThread"),
)
checker.check(
    "ChatPage no longer has T65 placeholder",
    not file_contains("resources/js/pages/ChatPage.vue", "Message thread coming in T65"),
)

# Markdown typography styles
checker.check(
    "CSS has markdown-content styles",
    file_contains("resources/css/app.css", ".markdown-content"),
)

# Shiki is installed
checker.check(
    "@shikijs/markdown-it is installed",
    file_contains("package.json", '"@shikijs/markdown-it"'),
)
checker.check(
    "shiki is installed",
    file_contains("package.json", '"shiki"'),
)
```

**Step 2: Run verification**

Run: `python3 verify/verify_m3.py`
Expected: T65 checks PASS (along with all prior checks).

**Step 3: Commit**

```bash
git add verify/verify_m3.py
git commit --no-gpg-sign -m "$(cat <<'EOF'
T65.9: Add T65 structural verification checks to verify_m3.py

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Final verification and task completion

**Step 1: Run full frontend test suite**

Run: `npx vitest run`
Expected: All tests PASS.

**Step 2: Run M3 verification**

Run: `python3 verify/verify_m3.py`
Expected: All checks PASS.

**Step 3: Update progress.md**

- Check `[x]` for T65
- Bold T66 as the next task
- Update milestone count to 18/27
- Update summary task count to 65/116 (56.0%)

**Step 4: Clear handoff.md**

Reset to empty template.

**Step 5: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "$(cat <<'EOF'
T65: Complete chat page message thread + markdown rendering

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```
