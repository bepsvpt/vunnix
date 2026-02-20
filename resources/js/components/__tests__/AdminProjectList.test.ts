import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/stores/admin';
import AdminProjectList from '../AdminProjectList.vue';

vi.mock('axios');

function makeProject(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        name: 'Project A',
        slug: 'project-a',
        gitlab_project_id: 42,
        description: null,
        enabled: true,
        webhook_configured: true,
        webhook_id: null,
        recent_task_count: 5,
        active_conversation_count: 2,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('adminProjectList \u2014 Configure button (T91)', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();
    });

    it('shows Configure button for enabled projects', () => {
        admin.projects = [makeProject()];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="configure-btn-1"]').exists()).toBe(true);
    });

    it('does not show Configure button for disabled projects', () => {
        admin.projects = [makeProject({ enabled: false, webhook_configured: false, recent_task_count: 0, active_conversation_count: 0 })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="configure-btn-1"]').exists()).toBe(false);
    });

    it('emits configure event with project data when Configure is clicked', async () => {
        admin.projects = [makeProject()];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="configure-btn-1"]').trigger('click');

        expect(wrapper.emitted('configure')).toBeTruthy();
        expect(wrapper.emitted('configure')![0][0]).toEqual({ id: 1, name: 'Project A' });
    });
});

describe('adminProjectList \u2014 loading and empty states', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();
    });

    it('shows loading state when admin.loading is true', () => {
        admin.loading = true;

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Loading projects...');
    });

    it('shows empty state when no projects exist', () => {
        admin.projects = [];
        admin.loading = false;

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('No projects found');
    });

    it('does not show empty state while loading', () => {
        admin.projects = [];
        admin.loading = true;

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).not.toContain('No projects found');
    });
});

describe('adminProjectList \u2014 project metadata display', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();
    });

    it('shows project name and GitLab project ID', () => {
        admin.projects = [makeProject({ name: 'My App', gitlab_project_id: 99 })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('My App');
        expect(wrapper.text()).toContain('GitLab #99');
    });

    it('shows slug for projects with a slug', () => {
        admin.projects = [makeProject({ slug: 'my-cool-project' })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('my-cool-project');
    });

    it('shows recent task count and active conversations for enabled projects', () => {
        admin.projects = [makeProject({ recent_task_count: 12, active_conversation_count: 4 })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('12 tasks (7d)');
        expect(wrapper.text()).toContain('4 active conversations');
    });

    it('does not show task/conversation stats for disabled projects', () => {
        admin.projects = [makeProject({ enabled: false, recent_task_count: 3, active_conversation_count: 1 })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).not.toContain('tasks (7d)');
        expect(wrapper.text()).not.toContain('active conversations');
    });

    it('shows Enabled status badge for enabled projects', () => {
        admin.projects = [makeProject({ enabled: true })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        const badge = wrapper.find('[data-testid="project-status-1"]');
        expect(badge.text()).toBe('Enabled');
    });

    it('shows Disabled status badge for disabled projects', () => {
        admin.projects = [makeProject({ enabled: false })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        const badge = wrapper.find('[data-testid="project-status-1"]');
        expect(badge.text()).toBe('Disabled');
    });
});

describe('adminProjectList \u2014 webhook configured badge', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();
    });

    it('shows "Webhook active" badge for enabled projects with webhook configured', () => {
        admin.projects = [makeProject({ enabled: true, webhook_configured: true })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Webhook active');
    });

    it('does not show "Webhook active" badge for enabled projects without webhook', () => {
        admin.projects = [makeProject({ enabled: true, webhook_configured: false })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).not.toContain('Webhook active');
    });

    it('does not show "Webhook active" badge for disabled projects even with webhook_configured', () => {
        admin.projects = [makeProject({ enabled: false, webhook_configured: true })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).not.toContain('Webhook active');
    });
});

describe('adminProjectList \u2014 handleEnable', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();
    });

    it('calls enableProject on store when Enable button is clicked', async () => {
        admin.projects = [makeProject({ id: 5, enabled: false })];
        vi.spyOn(admin, 'enableProject').mockResolvedValue({ success: true });

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="enable-btn-5"]').trigger('click');
        await flushPromises();

        expect(admin.enableProject).toHaveBeenCalledWith(5);
    });

    it('shows "Enabling..." text while operation is in progress', async () => {
        admin.projects = [makeProject({ id: 5, enabled: false })];
        let resolveEnable!: (value: { success: boolean }) => void;
        vi.spyOn(admin, 'enableProject').mockImplementation(
            () => new Promise((resolve) => {
                resolveEnable = resolve;
            }),
        );

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        const btn = wrapper.find('[data-testid="enable-btn-5"]');

        expect(btn.text()).toBe('Enable');

        await btn.trigger('click');
        await wrapper.vm.$nextTick();

        expect(btn.text()).toBe('Enabling...');
        expect((btn.element as HTMLButtonElement).disabled).toBe(true);

        resolveEnable({ success: true });
        await flushPromises();

        // Project is still disabled in store (mock doesn't update it), so Enable button still visible
        expect(btn.text()).toBe('Enable');
        expect((btn.element as HTMLButtonElement).disabled).toBe(false);
    });

    it('shows error banner when enableProject returns an error', async () => {
        admin.projects = [makeProject({ id: 5, enabled: false })];
        vi.spyOn(admin, 'enableProject').mockResolvedValue({ success: false, error: 'Bot not a member' });

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="enable-btn-5"]').trigger('click');
        await flushPromises();

        const errorBanner = wrapper.find('[data-testid="action-error"]');
        expect(errorBanner.exists()).toBe(true);
        expect(errorBanner.text()).toContain('Bot not a member');
    });

    it('shows warning banner when enableProject returns warnings', async () => {
        admin.projects = [makeProject({ id: 5, enabled: false })];
        vi.spyOn(admin, 'enableProject').mockResolvedValue({ success: true, warnings: ['Webhook already exists', 'Labels not synced'] });

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="enable-btn-5"]').trigger('click');
        await flushPromises();

        const warningBanner = wrapper.find('[data-testid="action-warnings"]');
        expect(warningBanner.exists()).toBe(true);
        expect(warningBanner.text()).toContain('Webhook already exists');
        expect(warningBanner.text()).toContain('Labels not synced');
    });

    it('clears previous error when a new enable action starts', async () => {
        admin.projects = [makeProject({ id: 5, enabled: false })];
        vi.spyOn(admin, 'enableProject')
            .mockResolvedValueOnce({ success: false, error: 'First error' })
            .mockResolvedValueOnce({ success: true });

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });

        await wrapper.find('[data-testid="enable-btn-5"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="action-error"]').exists()).toBe(true);

        await wrapper.find('[data-testid="enable-btn-5"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="action-error"]').exists()).toBe(false);
    });

    it('clears previous warnings when a new enable action starts', async () => {
        admin.projects = [makeProject({ id: 5, enabled: false })];
        vi.spyOn(admin, 'enableProject')
            .mockResolvedValueOnce({ success: true, warnings: ['Some warning'] })
            .mockResolvedValueOnce({ success: true });

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });

        await wrapper.find('[data-testid="enable-btn-5"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="action-warnings"]').exists()).toBe(true);

        await wrapper.find('[data-testid="enable-btn-5"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="action-warnings"]').exists()).toBe(false);
    });

    it('does not show error banner on success without warnings', async () => {
        admin.projects = [makeProject({ id: 5, enabled: false })];
        vi.spyOn(admin, 'enableProject').mockResolvedValue({ success: true });

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="enable-btn-5"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="action-error"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="action-warnings"]').exists()).toBe(false);
    });
});

describe('adminProjectList \u2014 handleDisable', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows confirmation dialog before disabling', async () => {
        admin.projects = [makeProject({ id: 3, enabled: true })];
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
        vi.spyOn(admin, 'disableProject').mockResolvedValue({ success: true });

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="disable-btn-3"]').trigger('click');
        await flushPromises();

        expect(confirmSpy).toHaveBeenCalledWith('Disable this project? The webhook will be removed, but all data will be preserved.');
        expect(admin.disableProject).not.toHaveBeenCalled();
    });

    it('calls disableProject when confirmation is accepted', async () => {
        admin.projects = [makeProject({ id: 3, enabled: true })];
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(admin, 'disableProject').mockResolvedValue({ success: true });

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="disable-btn-3"]').trigger('click');
        await flushPromises();

        expect(admin.disableProject).toHaveBeenCalledWith(3);
    });

    it('does not call disableProject when confirmation is rejected', async () => {
        admin.projects = [makeProject({ id: 3, enabled: true })];
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        vi.spyOn(admin, 'disableProject').mockResolvedValue({ success: true });

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="disable-btn-3"]').trigger('click');
        await flushPromises();

        expect(admin.disableProject).not.toHaveBeenCalled();
    });

    it('shows "Disabling..." text while operation is in progress', async () => {
        admin.projects = [makeProject({ id: 3, enabled: true })];
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        let resolveDisable!: (value: { success: boolean }) => void;
        vi.spyOn(admin, 'disableProject').mockImplementation(
            () => new Promise((resolve) => {
                resolveDisable = resolve;
            }),
        );

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        const btn = wrapper.find('[data-testid="disable-btn-3"]');

        expect(btn.text()).toBe('Disable');

        await btn.trigger('click');
        await wrapper.vm.$nextTick();

        expect(btn.text()).toBe('Disabling...');
        expect((btn.element as HTMLButtonElement).disabled).toBe(true);

        resolveDisable({ success: true });
        await flushPromises();

        expect(btn.text()).toBe('Disable');
        expect((btn.element as HTMLButtonElement).disabled).toBe(false);
    });

    it('shows error banner when disableProject returns an error', async () => {
        admin.projects = [makeProject({ id: 3, enabled: true })];
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(admin, 'disableProject').mockResolvedValue({ success: false, error: 'Webhook removal failed' });

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="disable-btn-3"]').trigger('click');
        await flushPromises();

        const errorBanner = wrapper.find('[data-testid="action-error"]');
        expect(errorBanner.exists()).toBe(true);
        expect(errorBanner.text()).toContain('Webhook removal failed');
    });

    it('does not show error banner on successful disable', async () => {
        admin.projects = [makeProject({ id: 3, enabled: true })];
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(admin, 'disableProject').mockResolvedValue({ success: true });

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="disable-btn-3"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="action-error"]').exists()).toBe(false);
    });
});

describe('adminProjectList \u2014 button visibility by state', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();
    });

    it('shows Enable button for disabled projects', () => {
        admin.projects = [makeProject({ id: 1, enabled: false })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="enable-btn-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="disable-btn-1"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="configure-btn-1"]').exists()).toBe(false);
    });

    it('shows Configure and Disable buttons for enabled projects', () => {
        admin.projects = [makeProject({ id: 1, enabled: true })];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="enable-btn-1"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="disable-btn-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="configure-btn-1"]').exists()).toBe(true);
    });

    it('renders multiple projects with correct buttons', () => {
        admin.projects = [
            makeProject({ id: 1, enabled: true }),
            makeProject({ id: 2, name: 'Project B', enabled: false }),
        ];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="configure-btn-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="disable-btn-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="enable-btn-2"]').exists()).toBe(true);
    });
});
