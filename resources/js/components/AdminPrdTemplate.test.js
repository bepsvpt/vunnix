import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import AdminPrdTemplate from './AdminPrdTemplate.vue';
import { useAdminStore } from '@/stores/admin';

vi.mock('axios');

describe('AdminPrdTemplate', () => {
    let pinia;
    let admin;

    const defaultProps = { projectId: 1, projectName: 'My Project' };

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchPrdTemplate').mockResolvedValue();
    });

    it('renders project name and back button', () => {
        const wrapper = mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(wrapper.text()).toContain('My Project');
        expect(wrapper.text()).toContain('PRD Template');
        expect(wrapper.find('[data-testid="back-btn"]').exists()).toBe(true);
    });

    it('fetches PRD template on mount', () => {
        mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(admin.fetchPrdTemplate).toHaveBeenCalledWith(1);
    });

    it('emits back event when back button clicked', async () => {
        const wrapper = mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.find('[data-testid="back-btn"]').trigger('click');
        expect(wrapper.emitted('back')).toBeTruthy();
    });

    it('shows loading state while fetching', () => {
        admin.prdTemplateLoading = true;
        admin.prdTemplate = null;
        const wrapper = mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(wrapper.text()).toContain('Loading PRD template');
    });

    it('displays source badge', async () => {
        admin.prdTemplate = { template: '# Test', source: 'project' };
        admin.prdTemplateLoading = false;
        const wrapper = mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(wrapper.find('[data-testid="template-source"]').text()).toBe('Project');
    });

    it('shows Default source badge when no override', async () => {
        admin.prdTemplate = { template: '# Default', source: 'default' };
        admin.prdTemplateLoading = false;
        const wrapper = mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(wrapper.find('[data-testid="template-source"]').text()).toBe('Default');
    });

    it('shows textarea with template content', async () => {
        admin.prdTemplate = { template: '# My Template', source: 'default' };
        admin.prdTemplateLoading = false;
        const wrapper = mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        const textarea = wrapper.find('[data-testid="template-editor"]');
        expect(textarea.exists()).toBe(true);
    });

    it('shows reset checkbox only when project override exists', async () => {
        admin.prdTemplate = { template: '# Test', source: 'default' };
        admin.prdTemplateLoading = false;
        const wrapper = mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(wrapper.find('[data-testid="reset-default-checkbox"]').exists()).toBe(false);

        // Set to project override
        admin.prdTemplate = { template: '# Custom', source: 'project' };
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="reset-default-checkbox"]').exists()).toBe(true);
    });

    it('has a save button', () => {
        admin.prdTemplate = { template: '# Test', source: 'default' };
        admin.prdTemplateLoading = false;
        const wrapper = mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(wrapper.find('[data-testid="save-template-btn"]').exists()).toBe(true);
    });

    it('calls updatePrdTemplate on save', async () => {
        vi.spyOn(admin, 'updatePrdTemplate').mockResolvedValue({ success: true });

        admin.prdTemplate = { template: '# Test', source: 'default' };
        admin.prdTemplateLoading = false;

        const wrapper = mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-template-btn"]').trigger('click');
        expect(admin.updatePrdTemplate).toHaveBeenCalledWith(1, '# Test');
    });

    it('shows success message after save', async () => {
        vi.spyOn(admin, 'updatePrdTemplate').mockResolvedValue({ success: true });

        admin.prdTemplate = { template: '# Test', source: 'default' };
        admin.prdTemplateLoading = false;

        const wrapper = mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-template-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="template-success"]').exists()).toBe(true);
    });

    it('shows error on save failure', async () => {
        vi.spyOn(admin, 'updatePrdTemplate').mockResolvedValue({
            success: false,
            error: 'Permission denied',
        });

        admin.prdTemplate = { template: '# Test', source: 'default' };
        admin.prdTemplateLoading = false;

        const wrapper = mount(AdminPrdTemplate, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-template-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="template-error"]').text()).toContain('Permission denied');
    });
});
