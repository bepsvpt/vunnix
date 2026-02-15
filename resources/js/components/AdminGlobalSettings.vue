<script setup>
import { ref, onMounted, watch } from 'vue';
import { useAdminStore } from '@/stores/admin';

const admin = useAdminStore();
const saving = ref(false);
const saveSuccess = ref(false);
const saveError = ref(null);

// Local form state — initialized from store settings or defaults
const form = ref({
    ai_model: 'opus',
    ai_language: 'en',
    timeout_minutes: 10,
    max_tokens: 8192,
    ai_prices_input: 5.0,
    ai_prices_output: 25.0,
    team_chat_webhook_url: '',
    team_chat_platform: 'slack',
    bot_pat_created_at: '',
});

const platformOptions = [
    { value: 'slack', label: 'Slack' },
    { value: 'mattermost', label: 'Mattermost' },
    { value: 'google_chat', label: 'Google Chat' },
    { value: 'generic', label: 'Generic Webhook' },
];

onMounted(() => {
    admin.fetchSettings();
});

// Sync form state when settings load from API
watch(() => admin.settings, (newSettings) => {
    for (const s of newSettings) {
        if (s.key === 'ai_prices' && typeof s.value === 'object') {
            form.value.ai_prices_input = s.value.input ?? 5.0;
            form.value.ai_prices_output = s.value.output ?? 25.0;
        } else if (s.key in form.value) {
            form.value[s.key] = s.value;
        }
    }
}, { immediate: true });

// Also seed from defaults when no DB overrides exist
watch(() => admin.settingsDefaults, (defaults) => {
    if (defaults && Object.keys(defaults).length > 0) {
        for (const [key, value] of Object.entries(defaults)) {
            if (key === 'ai_prices' && typeof value === 'object') {
                if (!admin.settings.find(s => s.key === 'ai_prices')) {
                    form.value.ai_prices_input = value.input ?? 5.0;
                    form.value.ai_prices_output = value.output ?? 25.0;
                }
            } else if (key in form.value && !admin.settings.find(s => s.key === key)) {
                form.value[key] = value;
            }
        }
    }
}, { immediate: true });

async function handleSave() {
    saving.value = true;
    saveSuccess.value = false;
    saveError.value = null;

    const settingsList = [
        { key: 'ai_model', value: form.value.ai_model, type: 'string' },
        { key: 'ai_language', value: form.value.ai_language, type: 'string' },
        { key: 'timeout_minutes', value: Number(form.value.timeout_minutes), type: 'integer' },
        { key: 'max_tokens', value: Number(form.value.max_tokens), type: 'integer' },
        { key: 'ai_prices', value: { input: Number(form.value.ai_prices_input), output: Number(form.value.ai_prices_output) }, type: 'json' },
        { key: 'team_chat_webhook_url', value: form.value.team_chat_webhook_url, type: 'string' },
        { key: 'team_chat_platform', value: form.value.team_chat_platform, type: 'string' },
    ];

    // Only include bot_pat_created_at if it has a value
    if (form.value.bot_pat_created_at) {
        settingsList.push({ key: 'bot_pat_created_at', value: form.value.bot_pat_created_at });
    }

    const result = await admin.updateSettings(settingsList);
    saving.value = false;

    if (result.success) {
        saveSuccess.value = true;
        setTimeout(() => { saveSuccess.value = false; }, 3000);
    } else {
        saveError.value = result.error;
    }
}
</script>

<template>
  <div>
    <h2 class="text-lg font-medium mb-4">Global Settings</h2>

    <!-- Loading state -->
    <div v-if="admin.settingsLoading" class="py-8 text-center text-zinc-500">
      Loading settings...
    </div>

    <template v-else>
      <!-- Success banner -->
      <div v-if="saveSuccess" class="mb-4 rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400" data-testid="settings-success">
        Settings saved successfully.
      </div>

      <!-- Error banner -->
      <div v-if="saveError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="settings-error">
        {{ saveError }}
      </div>

      <!-- API Key Status (display-only per D153) -->
      <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <h3 class="text-sm font-medium mb-2">Claude API Key</h3>
        <div data-testid="api-key-status" class="flex items-center gap-2">
          <span
            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
            :class="admin.apiKeyConfigured
              ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
              : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'"
          >
            {{ admin.apiKeyConfigured ? 'Configured' : 'Not configured' }}
          </span>
          <span class="text-xs text-zinc-500 dark:text-zinc-400">
            Managed via environment variable (ANTHROPIC_API_KEY)
          </span>
        </div>
      </div>

      <!-- AI Settings -->
      <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <h3 class="text-sm font-medium mb-3">AI Configuration</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div data-testid="setting-ai_model">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Default AI Model</label>
            <select v-model="form.ai_model" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
              <option value="opus">Opus</option>
              <option value="sonnet">Sonnet</option>
              <option value="haiku">Haiku</option>
            </select>
          </div>
          <div data-testid="setting-ai_language">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">AI Response Language</label>
            <input v-model="form.ai_language" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" placeholder="en" />
          </div>
          <div data-testid="setting-timeout_minutes">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Task Timeout (minutes)</label>
            <input v-model.number="form.timeout_minutes" type="number" min="1" max="60" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
          </div>
          <div data-testid="setting-max_tokens">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Max Tokens</label>
            <input v-model.number="form.max_tokens" type="number" min="1024" max="200000" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
          </div>
        </div>
      </div>

      <!-- Pricing -->
      <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <h3 class="text-sm font-medium mb-3">Cost Tracking Prices ($ per million tokens)</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div data-testid="setting-ai_prices_input">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Input Price</label>
            <input v-model.number="form.ai_prices_input" type="number" step="0.01" min="0" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
          </div>
          <div data-testid="setting-ai_prices_output">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Output Price</label>
            <input v-model.number="form.ai_prices_output" type="number" step="0.01" min="0" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
          </div>
        </div>
      </div>

      <!-- Team Chat Notifications -->
      <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-testid="section-team-chat">
        <h3 class="text-sm font-medium mb-3">Team Chat Notifications</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Webhook URL</label>
            <input v-model="form.team_chat_webhook_url" type="url" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" placeholder="https://hooks.slack.com/services/..." data-testid="setting-team_chat_webhook_url" />
          </div>
          <div>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Platform</label>
            <select v-model="form.team_chat_platform" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="setting-team_chat_platform">
              <option v-for="opt in platformOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Bot PAT Rotation (D144) -->
      <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <h3 class="text-sm font-medium mb-3">Bot Personal Access Token</h3>
        <div data-testid="setting-bot_pat_created_at">
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">PAT Creation Date</label>
          <input v-model="form.bot_pat_created_at" type="date" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
          <p class="mt-1 text-xs text-zinc-400">Used for rotation reminder at 5.5 months (T116)</p>
        </div>
      </div>

      <!-- Save button -->
      <div class="flex items-center gap-3">
        <button
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          data-testid="save-settings-btn"
          :disabled="saving"
          @click="handleSave"
        >
          {{ saving ? 'Saving...' : 'Save Settings' }}
        </button>
      </div>
    </template>
  </div>
</template>
