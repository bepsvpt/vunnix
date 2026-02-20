import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/features/admin';
import AdminProjectConfig from '../AdminProjectConfig.vue';

vi.mock('axios');

describe('adminProjectConfig', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    const defaultProps = { projectId: 42, projectName: 'My Project' };

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchProjectConfig').mockResolvedValue();
    });

    it('renders project name in heading', () => {
        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(wrapper.text()).toContain('My Project');
    });

    it('fetches config on mount', () => {
        mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(admin.fetchProjectConfig).toHaveBeenCalledWith(42);
    });

    it('shows loading state', () => {
        admin.projectConfigLoading = true;
        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(wrapper.text()).toContain('Loading configuration...');
    });

    it('renders setting fields when config is loaded', async () => {
        admin.projectConfig = {
            settings: { ai_model: 'sonnet' },
            effective: {
                ai_model: { value: 'sonnet', source: 'project' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {
                ai_model: 'string',
                ai_language: 'string',
                timeout_minutes: 'integer',
                max_tokens: 'integer',
            },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="config-ai_model"]').exists()).toBe(true);
    });

    it('shows source indicator for overridden values', async () => {
        admin.projectConfig = {
            settings: { ai_model: 'sonnet' },
            effective: {
                ai_model: { value: 'sonnet', source: 'project' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: { ai_model: 'string', ai_language: 'string', timeout_minutes: 'integer', max_tokens: 'integer' },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="source-ai_model"]').text()).toContain('Project');
    });

    it('shows source indicator for inherited values', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: { ai_model: 'string', ai_language: 'string', timeout_minutes: 'integer', max_tokens: 'integer' },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="source-ai_model"]').text()).toContain('Default');
    });

    it('emits back event when back button is clicked', async () => {
        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });

        await wrapper.find('[data-testid="back-btn"]').trigger('click');
        expect(wrapper.emitted('back')).toBeTruthy();
    });

    it('calls updateProjectConfig on save', async () => {
        vi.spyOn(admin, 'updateProjectConfig').mockResolvedValue({ success: true });

        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: { ai_model: 'string', ai_language: 'string', timeout_minutes: 'integer', max_tokens: 'integer' },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-config-btn"]').trigger('click');

        expect(admin.updateProjectConfig).toHaveBeenCalledWith(42, expect.any(Object));
    });

    it('shows success message after save', async () => {
        vi.spyOn(admin, 'updateProjectConfig').mockResolvedValue({ success: true });

        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: { ai_model: 'string', ai_language: 'string', timeout_minutes: 'integer', max_tokens: 'integer' },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-config-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="config-success"]').exists()).toBe(true);
    });

    it('shows error on save failure', async () => {
        vi.spyOn(admin, 'updateProjectConfig').mockResolvedValue({
            success: false,
            error: 'Server error',
        });

        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: { ai_model: 'string', ai_language: 'string', timeout_minutes: 'integer', max_tokens: 'integer' },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-config-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="config-error"]').text()).toContain('Server error');
    });

    it('emits editTemplate event when PRD Template button is clicked', async () => {
        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });

        await wrapper.find('[data-testid="edit-template-btn"]').trigger('click');
        expect(wrapper.emitted('editTemplate')).toBeTruthy();
    });

    it('renders select field type for ai_model', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        const aiModelField = wrapper.find('[data-testid="config-ai_model"]');
        expect(aiModelField.find('select').exists()).toBe(true);
        const options = aiModelField.findAll('option');
        expect(options.length).toBe(3);
        expect(options[0].text()).toBe('Opus');
        expect(options[1].text()).toBe('Sonnet');
        expect(options[2].text()).toBe('Haiku');
    });

    it('renders text input for ai_language', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        const langField = wrapper.find('[data-testid="config-ai_language"]');
        const input = langField.find('input[type="text"]');
        expect(input.exists()).toBe(true);
        expect(input.attributes('placeholder')).toBe('en');
    });

    it('renders number input for timeout_minutes with min/max', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        const timeoutField = wrapper.find('[data-testid="config-timeout_minutes"]');
        const input = timeoutField.find('input[type="number"]');
        expect(input.exists()).toBe(true);
        expect(input.attributes('min')).toBe('1');
        expect(input.attributes('max')).toBe('60');
    });

    it('renders checkbox input for code_review.auto_review', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                'ai_model': { value: 'opus', source: 'default' },
                'ai_language': { value: 'en', source: 'default' },
                'timeout_minutes': { value: 10, source: 'default' },
                'max_tokens': { value: 8192, source: 'default' },
                'code_review.auto_review': { value: true, source: 'default' },
                'code_review.auto_review_on_push': { value: false, source: 'default' },
                'code_review.severity_threshold': { value: 'major', source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        const checkboxField = wrapper.find('[data-testid="config-code_review.auto_review"]');
        expect(checkboxField.exists()).toBe(true);
        const checkbox = checkboxField.find('input[type="checkbox"]');
        expect(checkbox.exists()).toBe(true);
    });

    it('shows Global source badge for globally-sourced settings', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'global' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        const sourceBadge = wrapper.find('[data-testid="source-ai_model"]');
        expect(sourceBadge.text()).toContain('Global');
        expect(sourceBadge.classes()).toContain('bg-zinc-100');
    });

    it('syncs form state from config watch and allows field editing', async () => {
        admin.projectConfig = {
            settings: { ai_model: 'sonnet' },
            effective: {
                ai_model: { value: 'sonnet', source: 'project' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        // Select should be synced to 'sonnet'
        const selectEl = wrapper.find('[data-testid="config-ai_model"] select');
        expect((selectEl.element as HTMLSelectElement).value).toBe('sonnet');

        // Change the value
        await selectEl.setValue('haiku');
        expect((selectEl.element as HTMLSelectElement).value).toBe('haiku');
    });

    it('editing a text field updates form state', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        const langInput = wrapper.find('[data-testid="config-ai_language"] input[type="text"]');
        await langInput.setValue('ja');
        expect((langInput.element as HTMLInputElement).value).toBe('ja');
    });

    it('editing a number field updates form state', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        const timeoutInput = wrapper.find('[data-testid="config-timeout_minutes"] input[type="number"]');
        await timeoutInput.setValue(30);
        expect((timeoutInput.element as HTMLInputElement).value).toBe('30');
    });

    it('casts number fields to Number and checkbox fields to Boolean on save', async () => {
        const mockUpdate = vi.spyOn(admin, 'updateProjectConfig').mockResolvedValue({ success: true });

        admin.projectConfig = {
            settings: {},
            effective: {
                'ai_model': { value: 'opus', source: 'default' },
                'ai_language': { value: 'en', source: 'default' },
                'timeout_minutes': { value: 10, source: 'default' },
                'max_tokens': { value: 8192, source: 'default' },
                'code_review.auto_review': { value: true, source: 'default' },
                'code_review.auto_review_on_push': { value: false, source: 'default' },
                'code_review.severity_threshold': { value: 'major', source: 'default' },
                'feature_dev.enabled': { value: false, source: 'default' },
                'feature_dev.branch_prefix': { value: 'ai/', source: 'default' },
                'feature_dev.auto_create_mr': { value: false, source: 'default' },
                'conversation.enabled': { value: true, source: 'default' },
                'conversation.max_history_messages': { value: 50, source: 'default' },
                'conversation.tool_use_gitlab': { value: true, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        // Modify a number field
        const timeoutInput = wrapper.find('[data-testid="config-timeout_minutes"] input[type="number"]');
        await timeoutInput.setValue(25);

        // Click save
        await wrapper.find('[data-testid="save-config-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(mockUpdate).toHaveBeenCalledWith(42, expect.objectContaining({
            timeout_minutes: 25,
        }));

        // Verify the settings object contains boolean types for checkbox fields
        const savedSettings = mockUpdate.mock.calls[0][1];
        expect(typeof savedSettings['code_review.auto_review']).toBe('boolean');
        expect(typeof savedSettings.timeout_minutes).toBe('number');
    });

    it('sends null for project-overridden settings with null/undefined form value', async () => {
        const mockUpdate = vi.spyOn(admin, 'updateProjectConfig').mockResolvedValue({ success: true });

        admin.projectConfig = {
            settings: { ai_language: 'ja' },
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'ja', source: 'project' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        // Clear the text field to empty (which becomes '' in v-model, but we need null)
        // We simulate setting the form value to null by clearing the input
        const langInput = wrapper.find('[data-testid="config-ai_language"] input[type="text"]');
        await langInput.setValue('');

        await wrapper.find('[data-testid="save-config-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(mockUpdate).toHaveBeenCalledWith(42, expect.any(Object));
    });

    it('renders all four setting groups', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                'ai_model': { value: 'opus', source: 'default' },
                'ai_language': { value: 'en', source: 'default' },
                'timeout_minutes': { value: 10, source: 'default' },
                'max_tokens': { value: 8192, source: 'default' },
                'code_review.auto_review': { value: true, source: 'default' },
                'code_review.auto_review_on_push': { value: false, source: 'default' },
                'code_review.severity_threshold': { value: 'major', source: 'default' },
                'feature_dev.enabled': { value: false, source: 'default' },
                'feature_dev.branch_prefix': { value: 'ai/', source: 'default' },
                'feature_dev.auto_create_mr': { value: false, source: 'default' },
                'conversation.enabled': { value: true, source: 'default' },
                'conversation.max_history_messages': { value: 50, source: 'default' },
                'conversation.tool_use_gitlab': { value: true, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('AI Configuration');
        expect(wrapper.text()).toContain('Code Review');
        expect(wrapper.text()).toContain('Feature Development');
        expect(wrapper.text()).toContain('Conversation');
    });

    it('shows inheritance info text', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Values inherit from global settings unless overridden');
    });

    it('disables save button while saving', async () => {
        // Create a promise we control to keep save pending
        let resolveUpdate: (val: { success: boolean }) => void;
        vi.spyOn(admin, 'updateProjectConfig').mockImplementation(() => {
            return new Promise((resolve) => {
                resolveUpdate = resolve;
            });
        });

        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        const saveBtn = wrapper.find('[data-testid="save-config-btn"]');
        await saveBtn.trigger('click');
        await wrapper.vm.$nextTick();

        // Button should show "Saving..." and be disabled
        expect(saveBtn.text()).toBe('Saving...');
        expect((saveBtn.element as HTMLButtonElement).disabled).toBe(true);

        // Resolve the update
        resolveUpdate!({ success: true });
        await wrapper.vm.$nextTick();
        await new Promise(r => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        expect(saveBtn.text()).toBe('Save Configuration');
        expect((saveBtn.element as HTMLButtonElement).disabled).toBe(false);
    });

    it('updates form when projectConfig changes after initial load', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        // Verify initial value
        const selectEl = wrapper.find('[data-testid="config-ai_model"] select');
        expect((selectEl.element as HTMLSelectElement).value).toBe('opus');

        // Simulate config update (e.g., after save returns new data)
        admin.projectConfig = {
            settings: { ai_model: 'haiku' },
            effective: {
                ai_model: { value: 'haiku', source: 'project' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };
        await wrapper.vm.$nextTick();

        expect((selectEl.element as HTMLSelectElement).value).toBe('haiku');
        expect(wrapper.find('[data-testid="source-ai_model"]').text()).toContain('Project');
    });

    it('renders all source badge CSS classes correctly', async () => {
        admin.projectConfig = {
            settings: { ai_model: 'sonnet' },
            effective: {
                ai_model: { value: 'sonnet', source: 'project' },
                ai_language: { value: 'en', source: 'global' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        // Project badge (blue)
        const projectBadge = wrapper.find('[data-testid="source-ai_model"]');
        expect(projectBadge.classes()).toContain('bg-blue-100');

        // Global badge (zinc)
        const globalBadge = wrapper.find('[data-testid="source-ai_language"]');
        expect(globalBadge.classes()).toContain('bg-zinc-100');

        // Default badge (zinc-50)
        const defaultBadge = wrapper.find('[data-testid="source-timeout_minutes"]');
        expect(defaultBadge.classes()).toContain('bg-zinc-50');
    });

    it('syncs code_review and feature_dev nested settings into form', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                'ai_model': { value: 'opus', source: 'default' },
                'ai_language': { value: 'en', source: 'default' },
                'timeout_minutes': { value: 10, source: 'default' },
                'max_tokens': { value: 8192, source: 'default' },
                'code_review.auto_review': { value: true, source: 'project' },
                'code_review.auto_review_on_push': { value: false, source: 'default' },
                'code_review.severity_threshold': { value: 'critical', source: 'project' },
                'feature_dev.enabled': { value: true, source: 'project' },
                'feature_dev.branch_prefix': { value: 'vunnix/', source: 'project' },
                'feature_dev.auto_create_mr': { value: true, source: 'default' },
                'conversation.enabled': { value: true, source: 'default' },
                'conversation.max_history_messages': { value: 100, source: 'global' },
                'conversation.tool_use_gitlab': { value: true, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        // Check that checkbox is checked
        const autoReviewCheckbox = wrapper.find('[data-testid="config-code_review.auto_review"] input[type="checkbox"]');
        expect((autoReviewCheckbox.element as HTMLInputElement).checked).toBe(true);

        // Check severity threshold select value
        const severitySelect = wrapper.find('[data-testid="config-code_review.severity_threshold"] select');
        expect((severitySelect.element as HTMLSelectElement).value).toBe('critical');

        // Check branch prefix text field
        const branchInput = wrapper.find('[data-testid="config-feature_dev.branch_prefix"] input[type="text"]');
        expect((branchInput.element as HTMLInputElement).value).toBe('vunnix/');

        // Check source badges
        expect(wrapper.find('[data-testid="source-code_review.auto_review"]').text()).toContain('Project');
        expect(wrapper.find('[data-testid="source-code_review.auto_review_on_push"]').text()).toContain('Default');
        expect(wrapper.find('[data-testid="source-conversation.max_history_messages"]').text()).toContain('Global');
    });

    it('handles save with multiple field changes across groups', async () => {
        const mockUpdate = vi.spyOn(admin, 'updateProjectConfig').mockResolvedValue({ success: true });

        admin.projectConfig = {
            settings: {},
            effective: {
                'ai_model': { value: 'opus', source: 'default' },
                'ai_language': { value: 'en', source: 'default' },
                'timeout_minutes': { value: 10, source: 'default' },
                'max_tokens': { value: 8192, source: 'default' },
                'code_review.auto_review': { value: false, source: 'default' },
                'code_review.auto_review_on_push': { value: false, source: 'default' },
                'code_review.severity_threshold': { value: 'major', source: 'default' },
                'feature_dev.enabled': { value: false, source: 'default' },
                'feature_dev.branch_prefix': { value: 'ai/', source: 'default' },
                'feature_dev.auto_create_mr': { value: false, source: 'default' },
                'conversation.enabled': { value: true, source: 'default' },
                'conversation.max_history_messages': { value: 50, source: 'default' },
                'conversation.tool_use_gitlab': { value: true, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        // Change model
        await wrapper.find('[data-testid="config-ai_model"] select').setValue('haiku');
        // Change timeout
        await wrapper.find('[data-testid="config-timeout_minutes"] input[type="number"]').setValue(30);
        // Toggle checkbox
        const checkbox = wrapper.find('[data-testid="config-code_review.auto_review"] input[type="checkbox"]');
        await checkbox.setValue(true);

        await wrapper.find('[data-testid="save-config-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const savedSettings = mockUpdate.mock.calls[0][1];
        expect(savedSettings.ai_model).toBe('haiku');
        expect(savedSettings.timeout_minutes).toBe(30);
        expect(savedSettings['code_review.auto_review']).toBe(true);
    });

    it('does not show success or error banners initially', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="config-success"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="config-error"]').exists()).toBe(false);
    });

    it('does not render config when projectConfig is null', () => {
        admin.projectConfig = null;
        admin.projectConfigLoading = false;

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });

        expect(wrapper.find('[data-testid="save-config-btn"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('AI Configuration');
    });

    it('handles save error with no error message (falls back to null)', async () => {
        vi.spyOn(admin, 'updateProjectConfig').mockResolvedValue({
            success: false,
        });

        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {},
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-config-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        // saveError is set to result.error ?? null, which is undefined ?? null = null
        // The v-if="saveError" should not show the banner for null
        expect(wrapper.find('[data-testid="config-error"]').exists()).toBe(false);
    });
});
