<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useAdminStore } from '@/stores/admin';
import BaseButton from './ui/BaseButton.vue';
import BaseCard from './ui/BaseCard.vue';

interface FormState {
    ai_model: string;
    ai_language: string;
    timeout_minutes: number;
    max_tokens: number;
    ai_prices_input: number;
    ai_prices_output: number;
    team_chat_webhook_url: string;
    team_chat_platform: string;
    team_chat_enabled: boolean;
    team_chat_categories: {
        task_completed: boolean;
        task_failed: boolean;
        alert: boolean;
    };
    bot_pat_created_at: string;
    [key: string]: unknown;
}

interface WebhookResult {
    success: boolean;
    message?: string;
    error?: string;
}

const admin = useAdminStore();
const saving = ref(false);
const saveSuccess = ref(false);
const saveError = ref<string | null>(null);

// Local form state — initialized from store settings or defaults
const form = ref<FormState>({
    ai_model: 'opus',
    ai_language: 'en',
    timeout_minutes: 10,
    max_tokens: 8192,
    ai_prices_input: 5.0,
    ai_prices_output: 25.0,
    team_chat_webhook_url: '',
    team_chat_platform: 'slack',
    team_chat_enabled: false,
    team_chat_categories: {
        task_completed: true,
        task_failed: true,
        alert: true,
    },
    bot_pat_created_at: '',
});

const testingWebhook = ref(false);
const testWebhookResult = ref<WebhookResult | null>(null);

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
            const prices = s.value as Record<string, number>;
            form.value.ai_prices_input = prices.input ?? 5.0;
            form.value.ai_prices_output = prices.output ?? 25.0;
        } else if (s.key in form.value) {
            (form.value as Record<string, unknown>)[s.key] = s.value;
        }
    }
}, { immediate: true });

// Also seed from defaults when no DB overrides exist
watch(() => admin.settingsDefaults, (defaults) => {
    if (defaults && Object.keys(defaults).length > 0) {
        for (const [key, value] of Object.entries(defaults)) {
            if (key === 'ai_prices' && typeof value === 'object') {
                if (!admin.settings.find(s => s.key === 'ai_prices')) {
                    const prices = value as Record<string, number>;
                    form.value.ai_prices_input = prices.input ?? 5.0;
                    form.value.ai_prices_output = prices.output ?? 25.0;
                }
            } else if (key in form.value && !admin.settings.find(s => s.key === key)) {
                (form.value as Record<string, unknown>)[key] = value;
            }
        }
    }
}, { immediate: true });

async function handleSave() {
    saving.value = true;
    saveSuccess.value = false;
    saveError.value = null;

    const settingsList: Array<{ key: string; value: unknown; type?: string }> = [
        { key: 'ai_model', value: form.value.ai_model, type: 'string' },
        { key: 'ai_language', value: form.value.ai_language, type: 'string' },
        { key: 'timeout_minutes', value: Number(form.value.timeout_minutes), type: 'integer' },
        { key: 'max_tokens', value: Number(form.value.max_tokens), type: 'integer' },
        { key: 'ai_prices', value: { input: Number(form.value.ai_prices_input), output: Number(form.value.ai_prices_output) }, type: 'json' },
        { key: 'team_chat_webhook_url', value: form.value.team_chat_webhook_url, type: 'string' },
        { key: 'team_chat_platform', value: form.value.team_chat_platform, type: 'string' },
        { key: 'team_chat_enabled', value: form.value.team_chat_enabled, type: 'boolean' },
        { key: 'team_chat_categories', value: form.value.team_chat_categories, type: 'json' },
    ];

    // Only include bot_pat_created_at if it has a value
    if (form.value.bot_pat_created_at) {
        settingsList.push({ key: 'bot_pat_created_at', value: form.value.bot_pat_created_at });
    }

    const result = await admin.updateSettings(settingsList);
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

async function handleTestWebhook() {
    testingWebhook.value = true;
    testWebhookResult.value = null;

    const result = await admin.testWebhook(
        form.value.team_chat_webhook_url,
        form.value.team_chat_platform,
    );

    testingWebhook.value = false;
    testWebhookResult.value = result as WebhookResult;
    setTimeout(() => {
        testWebhookResult.value = null;
    }, 5000);
}
</script>

<template>
    <div>
        <h2 class="text-lg font-medium mb-4">
            Global Settings
        </h2>

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
            <BaseCard class="mb-6">
                <h3 class="text-sm font-medium mb-2">
                    Claude API Key
                </h3>
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
            </BaseCard>

            <!-- AI Settings -->
            <BaseCard class="mb-6">
                <h3 class="text-sm font-medium mb-3">
                    AI Configuration
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div data-testid="setting-ai_model">
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Default AI Model</label>
                        <select v-model="form.ai_model" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                            <option value="opus">
                                Opus
                            </option>
                            <option value="sonnet">
                                Sonnet
                            </option>
                            <option value="haiku">
                                Haiku
                            </option>
                        </select>
                    </div>
                    <div data-testid="setting-ai_language">
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">AI Response Language</label>
                        <input v-model="form.ai_language" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" placeholder="en">
                    </div>
                    <div data-testid="setting-timeout_minutes">
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Task Timeout (minutes)</label>
                        <input v-model.number="form.timeout_minutes" type="number" min="1" max="60" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                    </div>
                    <div data-testid="setting-max_tokens">
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Max Tokens</label>
                        <input v-model.number="form.max_tokens" type="number" min="1024" max="200000" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                    </div>
                </div>
            </BaseCard>

            <!-- Pricing -->
            <BaseCard class="mb-6">
                <h3 class="text-sm font-medium mb-3">
                    Cost Tracking Prices ($ per million tokens)
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div data-testid="setting-ai_prices_input">
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Input Price</label>
                        <input v-model.number="form.ai_prices_input" type="number" step="0.01" min="0" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                    </div>
                    <div data-testid="setting-ai_prices_output">
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Output Price</label>
                        <input v-model.number="form.ai_prices_output" type="number" step="0.01" min="0" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                    </div>
                </div>
            </BaseCard>

            <!-- Team Chat Notifications -->
            <BaseCard class="mb-6" data-testid="section-team-chat">
                <h3 class="text-sm font-medium mb-3">
                    Team Chat Notifications
                </h3>
                <div class="space-y-3">
                    <!-- Enabled toggle -->
                    <div class="flex items-center gap-2">
                        <input id="team-chat-enabled" v-model="form.team_chat_enabled" type="checkbox" class="rounded border-zinc-300 dark:border-zinc-600" data-testid="setting-team_chat_enabled">
                        <label for="team-chat-enabled" class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Enable team chat notifications</label>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Webhook URL</label>
                        <input v-model="form.team_chat_webhook_url" type="url" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" placeholder="https://hooks.slack.com/services/..." data-testid="setting-team_chat_webhook_url">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Platform</label>
                        <select v-model="form.team_chat_platform" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="setting-team_chat_platform">
                            <option v-for="opt in platformOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                    </div>
                    <!-- Notification categories -->
                    <div>
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Notification Categories</label>
                        <div class="flex flex-wrap gap-3" data-testid="setting-team_chat_categories">
                            <label class="flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                <input v-model="form.team_chat_categories.task_completed" type="checkbox" class="rounded border-zinc-300 dark:border-zinc-600"> Task completed
                            </label>
                            <label class="flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                <input v-model="form.team_chat_categories.task_failed" type="checkbox" class="rounded border-zinc-300 dark:border-zinc-600"> Task failed
                            </label>
                            <label class="flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                <input v-model="form.team_chat_categories.alert" type="checkbox" class="rounded border-zinc-300 dark:border-zinc-600"> Admin alerts
                            </label>
                        </div>
                    </div>
                    <!-- Test webhook button -->
                    <div class="flex items-center gap-2">
                        <BaseButton
                            variant="secondary"
                            size="sm"
                            data-testid="test-webhook-btn"
                            :disabled="testingWebhook || !form.team_chat_webhook_url"
                            @click="handleTestWebhook"
                        >
                            {{ testingWebhook ? 'Testing...' : 'Test Webhook' }}
                        </BaseButton>
                        <span v-if="testWebhookResult !== null" class="text-xs" :class="testWebhookResult.success ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" data-testid="test-webhook-result">
                            {{ testWebhookResult.message }}
                        </span>
                    </div>
                </div>
            </BaseCard>

            <!-- Bot PAT Rotation (D144) -->
            <BaseCard class="mb-6">
                <h3 class="text-sm font-medium mb-3">
                    Bot Personal Access Token
                </h3>
                <div data-testid="setting-bot_pat_created_at">
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">PAT Creation Date</label>
                    <input v-model="form.bot_pat_created_at" type="date" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                    <p class="mt-1 text-xs text-zinc-400">
                        Used for rotation reminder at 5.5 months (T116)
                    </p>
                </div>
            </BaseCard>

            <!-- Save button -->
            <div class="flex items-center gap-3">
                <BaseButton
                    variant="primary"
                    data-testid="save-settings-btn"
                    :disabled="saving"
                    @click="handleSave"
                >
                    {{ saving ? 'Saving...' : 'Save Settings' }}
                </BaseButton>
            </div>
        </template>
    </div>
</template>
