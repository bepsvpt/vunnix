import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/stores/admin';
import AdminGlobalSettings from './AdminGlobalSettings.vue';

vi.mock('axios');

describe('adminGlobalSettings', () => {
    let pinia;
    let admin;

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
});
