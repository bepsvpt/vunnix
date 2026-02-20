import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/auth';
import NewConversationDialog from '../NewConversationDialog.vue';

let pinia: ReturnType<typeof createPinia>;

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
    vi.restoreAllMocks();
});

function mountDialog() {
    return mount(NewConversationDialog, {
        global: {
            plugins: [pinia],
        },
    });
}

function setUserWithProjects(projects: Array<Record<string, unknown>>) {
    const auth = useAuthStore();
    auth.setUser({ id: 1, projects });
}

describe('newConversationDialog', () => {
    it('renders dialog with title and project select', () => {
        setUserWithProjects([
            { id: 1, name: 'Project Alpha', permissions: ['chat.access'] },
        ]);

        const wrapper = mountDialog();

        expect(wrapper.text()).toContain('New Conversation');
        expect(wrapper.text()).toContain('Select a project');
        expect(wrapper.find('select').exists()).toBe(true);
    });

    it('only shows projects with chat.access permission', () => {
        setUserWithProjects([
            { id: 1, name: 'Has Access', permissions: ['chat.access'] },
            { id: 2, name: 'No Access', permissions: ['tasks.view'] },
            { id: 3, name: 'Also Has Access', permissions: ['chat.access', 'tasks.view'] },
        ]);

        const wrapper = mountDialog();

        const options = wrapper.find('select').findAll('option');
        // Placeholder + 2 chat-accessible projects
        expect(options.length).toBe(3);
        expect(options[0].text()).toBe('Choose a project...');
        expect(options[1].text()).toBe('Has Access');
        expect(options[2].text()).toBe('Also Has Access');
    });

    it('shows warning when user has no chat-accessible projects', () => {
        setUserWithProjects([
            { id: 1, name: 'No Access', permissions: ['tasks.view'] },
        ]);

        const wrapper = mountDialog();

        expect(wrapper.text()).toContain('You don\'t have chat access to any projects.');
    });

    it('disables Start Conversation button when no project is selected', () => {
        setUserWithProjects([
            { id: 1, name: 'Project Alpha', permissions: ['chat.access'] },
        ]);

        const wrapper = mountDialog();

        const startBtn = wrapper.findAll('button').find(b => b.text().includes('Start Conversation'));
        expect(startBtn.attributes('disabled')).toBeDefined();
    });

    it('enables Start Conversation button when a project is selected', async () => {
        setUserWithProjects([
            { id: 1, name: 'Project Alpha', permissions: ['chat.access'] },
        ]);

        const wrapper = mountDialog();

        await wrapper.find('select').setValue(1);

        const startBtn = wrapper.findAll('button').find(b => b.text().includes('Start Conversation'));
        expect(startBtn.attributes('disabled')).toBeUndefined();
    });

    it('emits create event with project ID on submit', async () => {
        setUserWithProjects([
            { id: 42, name: 'Project Alpha', permissions: ['chat.access'] },
        ]);

        const wrapper = mountDialog();

        await wrapper.find('select').setValue(42);
        const startBtn = wrapper.findAll('button').find(b => b.text().includes('Start Conversation'));
        await startBtn.trigger('click');

        expect(wrapper.emitted('create')).toBeTruthy();
        expect(wrapper.emitted('create')[0]).toEqual([42]);
    });

    it('emits close event when Cancel is clicked', async () => {
        setUserWithProjects([
            { id: 1, name: 'Project Alpha', permissions: ['chat.access'] },
        ]);

        const wrapper = mountDialog();

        const cancelBtn = wrapper.findAll('button').find(b => b.text() === 'Cancel');
        await cancelBtn.trigger('click');

        expect(wrapper.emitted('close')).toBeTruthy();
    });

    it('emits close event when clicking outside the dialog (overlay)', async () => {
        setUserWithProjects([
            { id: 1, name: 'Project Alpha', permissions: ['chat.access'] },
        ]);

        const wrapper = mountDialog();

        // Click the overlay (outermost div with bg-black/40)
        await wrapper.find('.fixed.inset-0').trigger('click');

        expect(wrapper.emitted('close')).toBeTruthy();
    });

    it('shows Creating... text and disables button after submit', async () => {
        setUserWithProjects([
            { id: 1, name: 'Project Alpha', permissions: ['chat.access'] },
        ]);

        const wrapper = mountDialog();

        await wrapper.find('select').setValue(1);
        const startBtn = wrapper.findAll('button').find(b => b.text().includes('Start Conversation'));
        await startBtn.trigger('click');

        // After clicking, button should show "Creating..." and be disabled
        const updatedBtn = wrapper.findAll('button').find(b => b.text().includes('Creating...'));
        expect(updatedBtn).toBeTruthy();
        expect(updatedBtn.attributes('disabled')).toBeDefined();
    });

    it('does not emit create when no project is selected and submit is clicked', async () => {
        setUserWithProjects([
            { id: 1, name: 'Project Alpha', permissions: ['chat.access'] },
        ]);

        const wrapper = mountDialog();

        // Don't select any project, try to submit via keyboard or direct call
        const startBtn = wrapper.findAll('button').find(b => b.text().includes('Start Conversation'));
        await startBtn.trigger('click');

        expect(wrapper.emitted('create')).toBeFalsy();
    });
});
