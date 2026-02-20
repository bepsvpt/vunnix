import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/features/admin';
import AdminGlobalSettings from '../AdminGlobalSettings.vue';

vi.mock('axios');

describe('adminGlobalSettings', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchSettings').mockResolvedValue();
    });

    it('renders settings heading', () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Global Settings');
    });

    it('fetches settings on mount', () => {
        mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        expect(admin.fetchSettings).toHaveBeenCalled();
    });

    it('shows loading state', () => {
        admin.settingsLoading = true;
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Loading settings...');
    });

    it('shows API key configured status', async () => {
        admin.apiKeyConfigured = true;
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="api-key-status"]').text()).toContain('Configured');
    });

    it('shows API key not configured status', async () => {
        admin.apiKeyConfigured = false;
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="api-key-status"]').text()).toContain('Not configured');
    });

    it('renders editable settings fields', async () => {
        admin.settings = [
            { key: 'ai_model', value: 'opus', type: 'string', description: 'Default AI model' },
            { key: 'ai_language', value: 'en', type: 'string', description: 'AI response language' },
            { key: 'timeout_minutes', value: 10, type: 'integer', description: 'Task timeout' },
        ];
        admin.settingsDefaults = { ai_model: 'opus', ai_language: 'en', timeout_minutes: 10, max_tokens: 8192 };

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="setting-ai_model"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="setting-ai_language"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="setting-timeout_minutes"]').exists()).toBe(true);
    });

    it('shows save button', () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="save-settings-btn"]').exists()).toBe(true);
    });

    it('calls updateSettings on save', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: true });
        admin.settingsDefaults = { ai_model: 'opus', ai_language: 'en', timeout_minutes: 10, max_tokens: 8192 };

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');

        expect(admin.updateSettings).toHaveBeenCalled();
    });

    it('shows success message after save', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: true });
        admin.settingsDefaults = { ai_model: 'opus', ai_language: 'en', timeout_minutes: 10, max_tokens: 8192 };

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="settings-success"]').exists()).toBe(true);
    });

    it('shows error message on save failure', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: false, error: 'Validation failed' });
        admin.settingsDefaults = { ai_model: 'opus', ai_language: 'en', timeout_minutes: 10, max_tokens: 8192 };

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="settings-error"]').text()).toContain('Validation failed');
    });

    it('renders team chat webhook section', async () => {
        admin.settingsDefaults = { ai_model: 'opus' };
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="section-team-chat"]').exists()).toBe(true);
    });

    it('renders bot PAT created date field', async () => {
        admin.settingsDefaults = { ai_model: 'opus' };
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="setting-bot_pat_created_at"]').exists()).toBe(true);
    });

    it('renders team chat enabled toggle', async () => {
        admin.settingsDefaults = { ai_model: 'opus' };
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="setting-team_chat_enabled"]').exists()).toBe(true);
    });

    it('renders test webhook button', async () => {
        admin.settingsDefaults = { ai_model: 'opus' };
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="test-webhook-btn"]').exists()).toBe(true);
    });

    it('renders notification category checkboxes', async () => {
        admin.settingsDefaults = { ai_model: 'opus' };
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        const categoriesSection = wrapper.find('[data-testid="setting-team_chat_categories"]');
        expect(categoriesSection.exists()).toBe(true);
        expect(categoriesSection.findAll('input[type="checkbox"]')).toHaveLength(3);
    });

    it('disables test webhook button when URL is empty', async () => {
        admin.settingsDefaults = { ai_model: 'opus' };
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        const btn = wrapper.find('[data-testid="test-webhook-btn"]');
        expect(btn.attributes('disabled')).toBeDefined();
    });

    it('handleSave constructs settings list with all form fields including ai_prices aggregation', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: true });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // Set form values via inputs
        const modelSelect = wrapper.find('[data-testid="setting-ai_model"] select');
        await modelSelect.setValue('sonnet');

        const langInput = wrapper.find('[data-testid="setting-ai_language"] input');
        await langInput.setValue('ja');

        const timeoutInput = wrapper.find('[data-testid="setting-timeout_minutes"] input');
        await timeoutInput.setValue(15);

        const maxTokensInput = wrapper.find('[data-testid="setting-max_tokens"] input');
        await maxTokensInput.setValue(16384);

        const inputPriceInput = wrapper.find('[data-testid="setting-ai_prices_input"] input');
        await inputPriceInput.setValue(3.0);

        const outputPriceInput = wrapper.find('[data-testid="setting-ai_prices_output"] input');
        await outputPriceInput.setValue(15.0);

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(admin.updateSettings).toHaveBeenCalledTimes(1);
        const settingsList = (admin.updateSettings as ReturnType<typeof vi.fn>).mock.calls[0][0];

        // Verify ai_prices is aggregated from two separate fields
        const aiPrices = settingsList.find((s: { key: string }) => s.key === 'ai_prices');
        expect(aiPrices).toBeDefined();
        expect(aiPrices.value).toEqual({ input: 3, output: 15 });
        expect(aiPrices.type).toBe('json');

        // Verify other fields
        const aiModel = settingsList.find((s: { key: string }) => s.key === 'ai_model');
        expect(aiModel.value).toBe('sonnet');
        expect(aiModel.type).toBe('string');

        const aiLang = settingsList.find((s: { key: string }) => s.key === 'ai_language');
        expect(aiLang.value).toBe('ja');

        const timeout = settingsList.find((s: { key: string }) => s.key === 'timeout_minutes');
        expect(timeout.type).toBe('integer');

        const maxTokens = settingsList.find((s: { key: string }) => s.key === 'max_tokens');
        expect(maxTokens.type).toBe('integer');
    });

    it('handleSave includes team_chat fields', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: true });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        const webhookInput = wrapper.find('[data-testid="setting-team_chat_webhook_url"]');
        await webhookInput.setValue('https://hooks.slack.com/services/xxx');

        const platformSelect = wrapper.find('[data-testid="setting-team_chat_platform"]');
        await platformSelect.setValue('mattermost');

        const enabledCheckbox = wrapper.find('[data-testid="setting-team_chat_enabled"]');
        await enabledCheckbox.setValue(true);

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const settingsList = (admin.updateSettings as ReturnType<typeof vi.fn>).mock.calls[0][0];

        const webhookUrl = settingsList.find((s: { key: string }) => s.key === 'team_chat_webhook_url');
        expect(webhookUrl.value).toBe('https://hooks.slack.com/services/xxx');

        const platform = settingsList.find((s: { key: string }) => s.key === 'team_chat_platform');
        expect(platform.value).toBe('mattermost');

        const enabled = settingsList.find((s: { key: string }) => s.key === 'team_chat_enabled');
        expect(enabled.value).toBe(true);
        expect(enabled.type).toBe('boolean');

        const categories = settingsList.find((s: { key: string }) => s.key === 'team_chat_categories');
        expect(categories.type).toBe('json');
    });

    it('handleSave includes bot_pat_created_at only when set', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: true });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // Save without setting bot_pat_created_at
        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        let settingsList = (admin.updateSettings as ReturnType<typeof vi.fn>).mock.calls[0][0];
        let botPat = settingsList.find((s: { key: string }) => s.key === 'bot_pat_created_at');
        expect(botPat).toBeUndefined();

        // Now set the value and save again
        const botPatInput = wrapper.find('[data-testid="setting-bot_pat_created_at"] input');
        await botPatInput.setValue('2026-01-15');

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        settingsList = (admin.updateSettings as ReturnType<typeof vi.fn>).mock.calls[1][0];
        botPat = settingsList.find((s: { key: string }) => s.key === 'bot_pat_created_at');
        expect(botPat).toBeDefined();
        expect(botPat.value).toBe('2026-01-15');
    });

    it('handleSave shows success banner that auto-dismisses', async () => {
        vi.useFakeTimers();
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: true });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="settings-success"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-success"]').text()).toContain('Settings saved successfully');

        // After 3 seconds, success banner should auto-dismiss
        vi.advanceTimersByTime(3000);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="settings-success"]').exists()).toBe(false);

        vi.useRealTimers();
    });

    it('handleSave shows error banner on failure', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: false, error: 'Server error occurred' });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="settings-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-error"]').text()).toContain('Server error occurred');
        // Success should NOT be shown
        expect(wrapper.find('[data-testid="settings-success"]').exists()).toBe(false);
    });

    it('handleSave disables button while saving', async () => {
        let resolveUpdate!: (value: { success: boolean }) => void;
        vi.spyOn(admin, 'updateSettings').mockImplementation(() => {
            return new Promise((resolve) => {
                resolveUpdate = resolve;
            });
        });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        const saveBtn = wrapper.find('[data-testid="save-settings-btn"]');
        // Trigger click but don't await it — we want to observe the in-progress state
        saveBtn.trigger('click');
        await wrapper.vm.$nextTick();

        // Button should show "Saving..." and be disabled
        expect(saveBtn.text()).toBe('Saving...');
        expect(saveBtn.attributes('disabled')).toBeDefined();

        // Resolve the promise and flush
        resolveUpdate({ success: true });
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        expect(saveBtn.text()).toBe('Save Settings');
    });

    it('handleSave clears previous error on new save attempt', async () => {
        const updateMock = vi.spyOn(admin, 'updateSettings');
        updateMock.mockResolvedValueOnce({ success: false, error: 'First error' });
        updateMock.mockResolvedValueOnce({ success: true });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // First save: fails
        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="settings-error"]').exists()).toBe(true);

        // Second save: succeeds — error should be cleared
        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="settings-error"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="settings-success"]').exists()).toBe(true);
    });

    it('handleTestWebhook calls testWebhook and shows success result', async () => {
        vi.useFakeTimers();
        vi.spyOn(admin, 'testWebhook').mockResolvedValue({
            success: true,
            message: 'Webhook delivered successfully',
        });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // Set webhook URL so button is enabled
        const webhookInput = wrapper.find('[data-testid="setting-team_chat_webhook_url"]');
        await webhookInput.setValue('https://hooks.slack.com/services/test');
        await wrapper.vm.$nextTick();

        const testBtn = wrapper.find('[data-testid="test-webhook-btn"]');
        expect(testBtn.attributes('disabled')).toBeUndefined();

        await testBtn.trigger('click');
        await wrapper.vm.$nextTick();

        expect(admin.testWebhook).toHaveBeenCalledWith(
            'https://hooks.slack.com/services/test',
            'slack',
        );

        const result = wrapper.find('[data-testid="test-webhook-result"]');
        expect(result.exists()).toBe(true);
        expect(result.text()).toContain('Webhook delivered successfully');
        // Success color class
        expect(result.classes()).toContain('text-green-600');

        // Auto-dismiss after 5 seconds
        vi.advanceTimersByTime(5000);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="test-webhook-result"]').exists()).toBe(false);

        vi.useRealTimers();
    });

    it('handleTestWebhook shows failure result', async () => {
        vi.spyOn(admin, 'testWebhook').mockResolvedValue({
            success: false,
            message: 'Connection refused',
        });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        const webhookInput = wrapper.find('[data-testid="setting-team_chat_webhook_url"]');
        await webhookInput.setValue('https://bad-url.example.com');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="test-webhook-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const result = wrapper.find('[data-testid="test-webhook-result"]');
        expect(result.exists()).toBe(true);
        expect(result.text()).toContain('Connection refused');
        // Failure color class
        expect(result.classes()).toContain('text-red-600');
    });

    it('handleTestWebhook passes selected platform', async () => {
        vi.spyOn(admin, 'testWebhook').mockResolvedValue({ success: true, message: 'OK' });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        const webhookInput = wrapper.find('[data-testid="setting-team_chat_webhook_url"]');
        await webhookInput.setValue('https://chat.example.com/webhook');

        const platformSelect = wrapper.find('[data-testid="setting-team_chat_platform"]');
        await platformSelect.setValue('google_chat');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="test-webhook-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(admin.testWebhook).toHaveBeenCalledWith(
            'https://chat.example.com/webhook',
            'google_chat',
        );
    });

    it('handleTestWebhook shows Testing... text while in progress', async () => {
        let resolveTest!: (value: { success: boolean; message: string }) => void;
        vi.spyOn(admin, 'testWebhook').mockImplementation(() => {
            return new Promise((resolve) => {
                resolveTest = resolve;
            });
        });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        const webhookInput = wrapper.find('[data-testid="setting-team_chat_webhook_url"]');
        await webhookInput.setValue('https://hooks.slack.com/test');
        await wrapper.vm.$nextTick();

        const testBtn = wrapper.find('[data-testid="test-webhook-btn"]');
        // Trigger click but don't await — observe in-progress state
        testBtn.trigger('click');
        await wrapper.vm.$nextTick();

        expect(testBtn.text()).toBe('Testing...');
        expect(testBtn.attributes('disabled')).toBeDefined();

        resolveTest({ success: true, message: 'OK' });
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        expect(testBtn.text()).toBe('Test Webhook');
    });

    it('watcher syncs form from admin.settings', async () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // Update store settings — watcher should sync form
        admin.settings = [
            { key: 'ai_model', value: 'haiku', type: 'string', description: '' },
            { key: 'ai_language', value: 'fr', type: 'string', description: '' },
            { key: 'timeout_minutes', value: 30, type: 'integer', description: '' },
            { key: 'max_tokens', value: 4096, type: 'integer', description: '' },
            { key: 'ai_prices', value: { input: 1.5, output: 7.5 }, type: 'json', description: '' },
            { key: 'team_chat_webhook_url', value: 'https://hooks.test.com', type: 'string', description: '' },
            { key: 'team_chat_platform', value: 'mattermost', type: 'string', description: '' },
            { key: 'team_chat_enabled', value: true, type: 'boolean', description: '' },
        ];
        await wrapper.vm.$nextTick();

        // Verify form fields reflect the synced values
        expect((wrapper.find('[data-testid="setting-ai_model"] select').element as HTMLSelectElement).value).toBe('haiku');
        expect((wrapper.find('[data-testid="setting-ai_language"] input').element as HTMLInputElement).value).toBe('fr');
        expect((wrapper.find('[data-testid="setting-timeout_minutes"] input').element as HTMLInputElement).value).toBe('30');
        expect((wrapper.find('[data-testid="setting-max_tokens"] input').element as HTMLInputElement).value).toBe('4096');
        expect((wrapper.find('[data-testid="setting-ai_prices_input"] input').element as HTMLInputElement).value).toBe('1.5');
        expect((wrapper.find('[data-testid="setting-ai_prices_output"] input').element as HTMLInputElement).value).toBe('7.5');
        expect((wrapper.find('[data-testid="setting-team_chat_webhook_url"]').element as HTMLInputElement).value).toBe('https://hooks.test.com');
        expect((wrapper.find('[data-testid="setting-team_chat_platform"]').element as HTMLSelectElement).value).toBe('mattermost');
    });

    it('watcher syncs form from admin.settingsDefaults when no DB settings exist', async () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // Set defaults (no settings in DB)
        admin.settings = [];
        admin.settingsDefaults = {
            ai_model: 'sonnet',
            ai_language: 'de',
            timeout_minutes: 20,
            max_tokens: 32000,
            ai_prices: { input: 2.0, output: 10.0 },
        };
        await wrapper.vm.$nextTick();

        expect((wrapper.find('[data-testid="setting-ai_model"] select').element as HTMLSelectElement).value).toBe('sonnet');
        expect((wrapper.find('[data-testid="setting-ai_language"] input').element as HTMLInputElement).value).toBe('de');
        expect((wrapper.find('[data-testid="setting-timeout_minutes"] input').element as HTMLInputElement).value).toBe('20');
        expect((wrapper.find('[data-testid="setting-max_tokens"] input').element as HTMLInputElement).value).toBe('32000');
        expect((wrapper.find('[data-testid="setting-ai_prices_input"] input').element as HTMLInputElement).value).toBe('2');
        expect((wrapper.find('[data-testid="setting-ai_prices_output"] input').element as HTMLInputElement).value).toBe('10');
    });

    it('watcher prioritizes DB settings over defaults', async () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // DB has ai_model set to 'haiku', defaults say 'sonnet'
        admin.settings = [
            { key: 'ai_model', value: 'haiku', type: 'string', description: '' },
        ];
        admin.settingsDefaults = {
            ai_model: 'sonnet',
            ai_language: 'es',
        };
        await wrapper.vm.$nextTick();

        // ai_model should be 'haiku' from DB, not 'sonnet' from defaults
        expect((wrapper.find('[data-testid="setting-ai_model"] select').element as HTMLSelectElement).value).toBe('haiku');
        // ai_language should come from defaults since not in DB
        expect((wrapper.find('[data-testid="setting-ai_language"] input').element as HTMLInputElement).value).toBe('es');
    });

    it('watcher handles ai_prices defaults not overriding DB values', async () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        admin.settings = [
            { key: 'ai_prices', value: { input: 8.0, output: 40.0 }, type: 'json', description: '' },
        ];
        admin.settingsDefaults = {
            ai_prices: { input: 5.0, output: 25.0 },
        };
        await wrapper.vm.$nextTick();

        // Should use DB values, not defaults
        expect((wrapper.find('[data-testid="setting-ai_prices_input"] input').element as HTMLInputElement).value).toBe('8');
        expect((wrapper.find('[data-testid="setting-ai_prices_output"] input').element as HTMLInputElement).value).toBe('40');
    });

    it('input fields update form state via v-model', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: true });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // Change AI model
        await wrapper.find('[data-testid="setting-ai_model"] select').setValue('haiku');
        // Change language
        await wrapper.find('[data-testid="setting-ai_language"] input').setValue('ko');
        // Change timeout
        await wrapper.find('[data-testid="setting-timeout_minutes"] input').setValue(45);
        // Change max tokens
        await wrapper.find('[data-testid="setting-max_tokens"] input').setValue(100000);

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const settingsList = (admin.updateSettings as ReturnType<typeof vi.fn>).mock.calls[0][0];
        expect(settingsList.find((s: { key: string }) => s.key === 'ai_model').value).toBe('haiku');
        expect(settingsList.find((s: { key: string }) => s.key === 'ai_language').value).toBe('ko');
    });

    it('platform options dropdown renders all platform choices', async () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        const platformOptions = wrapper.find('[data-testid="setting-team_chat_platform"]').findAll('option');
        expect(platformOptions).toHaveLength(4);
        expect(platformOptions[0].text()).toBe('Slack');
        expect(platformOptions[0].attributes('value')).toBe('slack');
        expect(platformOptions[1].text()).toBe('Mattermost');
        expect(platformOptions[1].attributes('value')).toBe('mattermost');
        expect(platformOptions[2].text()).toBe('Google Chat');
        expect(platformOptions[2].attributes('value')).toBe('google_chat');
        expect(platformOptions[3].text()).toBe('Generic Webhook');
        expect(platformOptions[3].attributes('value')).toBe('generic');
    });

    it('notification category checkboxes toggle independently', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: true });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        const categoryCheckboxes = wrapper.find('[data-testid="setting-team_chat_categories"]').findAll('input[type="checkbox"]');
        expect(categoryCheckboxes).toHaveLength(3);

        // Uncheck "task_completed" (first checkbox) — it's checked by default
        await categoryCheckboxes[0].setValue(false);
        // Uncheck "alert" (third checkbox)
        await categoryCheckboxes[2].setValue(false);

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const settingsList = (admin.updateSettings as ReturnType<typeof vi.fn>).mock.calls[0][0];
        const categories = settingsList.find((s: { key: string }) => s.key === 'team_chat_categories');
        expect(categories.value).toEqual({
            task_completed: false,
            task_failed: true,
            alert: false,
        });
    });

    it('hides form fields when loading', () => {
        admin.settingsLoading = true;
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Loading settings...');
        expect(wrapper.find('[data-testid="save-settings-btn"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="setting-ai_model"]').exists()).toBe(false);
    });

    it('shows form fields when not loading', () => {
        admin.settingsLoading = false;
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        expect(wrapper.text()).not.toContain('Loading settings...');
        expect(wrapper.find('[data-testid="save-settings-btn"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="setting-ai_model"]').exists()).toBe(true);
    });

    it('renders AI configuration section with model options', async () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        const modelOptions = wrapper.find('[data-testid="setting-ai_model"] select').findAll('option');
        expect(modelOptions).toHaveLength(3);
        expect(modelOptions[0].text()).toBe('Opus');
        expect(modelOptions[1].text()).toBe('Sonnet');
        expect(modelOptions[2].text()).toBe('Haiku');
    });

    it('renders pricing section with input and output fields', async () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="setting-ai_prices_input"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="setting-ai_prices_output"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Cost Tracking Prices');
        expect(wrapper.text()).toContain('Input Price');
        expect(wrapper.text()).toContain('Output Price');
    });

    it('renders team chat enabled toggle and webhook URL field', async () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Team Chat Notifications');
        expect(wrapper.text()).toContain('Enable team chat notifications');
        expect(wrapper.find('[data-testid="setting-team_chat_enabled"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="setting-team_chat_webhook_url"]').exists()).toBe(true);
    });

    it('renders bot PAT section with date input and rotation reminder note', async () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Bot Personal Access Token');
        expect(wrapper.text()).toContain('PAT Creation Date');
        expect(wrapper.text()).toContain('rotation reminder at 5.5 months');
        const dateInput = wrapper.find('[data-testid="setting-bot_pat_created_at"] input');
        expect(dateInput.attributes('type')).toBe('date');
    });

    it('renders environment variable note for API key', async () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Managed via environment variable (ANTHROPIC_API_KEY)');
    });

    it('handleSave handles error result with no error message', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: false });

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Error banner should not show (error is null)
        expect(wrapper.find('[data-testid="settings-success"]').exists()).toBe(false);
    });
});
