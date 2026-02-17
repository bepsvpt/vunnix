import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/stores/admin';
import AdminProjectConfig from './AdminProjectConfig.vue';

vi.mock('axios');

describe('adminProjectConfig', () => {
    let pinia;
    let admin;

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
});
