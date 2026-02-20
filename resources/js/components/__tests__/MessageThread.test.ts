import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useConversationsStore } from '@/stores/conversations';
import ActionPreviewCard from '../ActionPreviewCard.vue';
import MessageBubble from '../MessageBubble.vue';
import MessageComposer from '../MessageComposer.vue';
import MessageThread from '../MessageThread.vue';
import PinnedTaskBar from '../PinnedTaskBar.vue';
import ResultCard from '../ResultCard.vue';

vi.mock('axios');
const mockedAxios = vi.mocked(axios, true);

// Mock markdown module to avoid Shiki async loading
vi.mock('@/lib/markdown', () => ({
    getMarkdownRenderer: () => ({
        render: (content: string) => `<p>${content}</p>`,
    }),
    isHighlightReady: (): boolean => false,
    onHighlightLoaded: vi.fn(),
}));

let pinia: ReturnType<typeof createPinia>;

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
    vi.restoreAllMocks();
    mockedAxios.get.mockResolvedValue({
        data: { data: [], meta: { next_cursor: null } },
    });
});

function mountThread() {
    return mount(MessageThread, {
        global: { plugins: [pinia] },
    });
}

describe('messageThread', () => {
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

    it('shows retryable error with amber alert and dismiss button', async () => {
        const store = useConversationsStore();
        store.messagesError = 'The AI service is temporarily busy.';
        store.streamRetryable = true;

        const wrapper = mountThread();
        const alert = wrapper.find('[data-testid="retryable-error"]');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('The AI service is temporarily busy.');
        expect(alert.text()).toContain('You can resend your message to try again.');

        await alert.find('button[aria-label="Dismiss"]').trigger('click');
        expect(store.messagesError).toBeNull();
        expect(store.streamRetryable).toBe(false);
    });

    it('shows terminal error with red alert and dismiss button', async () => {
        const store = useConversationsStore();
        store.messagesError = 'An error occurred while generating the response.';
        store.streamRetryable = false;

        const wrapper = mountThread();
        expect(wrapper.find('[data-testid="retryable-error"]').exists()).toBe(false);
        const alert = wrapper.find('[data-testid="terminal-error"]');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('An error occurred while generating the response.');
        expect(alert.text()).toContain('This error cannot be resolved by retrying.');

        await alert.find('button[aria-label="Dismiss"]').trigger('click');
        expect(store.messagesError).toBeNull();
    });

    it('shows empty state when no messages and not loading', () => {
        const store = useConversationsStore();
        store.messages = [];
        store.messagesLoading = false;

        const wrapper = mountThread();
        expect(wrapper.find('[data-testid="empty-thread"]').exists()).toBe(true);
    });

    it('calls streamMessage on composer send event', async () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';
        // Spy on streamMessage to verify it's called instead of sendMessage
        const streamSpy = vi.spyOn(store, 'streamMessage').mockResolvedValue();

        const wrapper = mountThread();
        const composer = wrapper.findComponent(MessageComposer);
        await composer.vm.$emit('send', 'Hello AI');
        await flushPromises();

        expect(streamSpy).toHaveBeenCalledWith('Hello AI');
    });

    it('disables composer while sending', () => {
        const store = useConversationsStore();
        store.sending = true;

        const wrapper = mountThread();
        const composer = wrapper.findComponent(MessageComposer);
        expect(composer.props('disabled')).toBe(true);
    });

    it('disables composer while streaming', () => {
        const store = useConversationsStore();
        store.streaming = true;

        const wrapper = mountThread();
        const composer = wrapper.findComponent(MessageComposer);
        expect(composer.props('disabled')).toBe(true);
    });

    describe('streaming display', () => {
        it('shows typing indicator when streaming starts and no content yet', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.streaming = true;
            store.streamingContent = '';

            const wrapper = mountThread();
            expect(wrapper.find('[data-testid="typing-indicator"]').exists()).toBe(true);
        });

        it('shows streaming bubble with partial content during streaming', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.streaming = true;
            store.streamingContent = 'Partial response';

            const wrapper = mountThread();
            expect(wrapper.find('[data-testid="streaming-bubble"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="streaming-bubble"]').text()).toContain('Partial response');
        });

        it('does not show streaming bubble when not streaming', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.streaming = false;
            store.streamingContent = '';

            const wrapper = mountThread();
            expect(wrapper.find('[data-testid="streaming-bubble"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="typing-indicator"]').exists()).toBe(false);
        });

        it('shows typing indicator even when streamingContent has content', () => {
            const store = useConversationsStore();
            store.messages = [];
            store.streaming = true;
            store.streamingContent = 'Hello';

            const wrapper = mountThread();
            // Typing indicator visible alongside streaming bubble to show "still generating"
            expect(wrapper.find('[data-testid="typing-indicator"]').exists()).toBe(true);
        });

        it('streaming bubble is styled as assistant message (left-aligned)', () => {
            const store = useConversationsStore();
            store.messages = [];
            store.streaming = true;
            store.streamingContent = 'Some text';

            const wrapper = mountThread();
            const bubble = wrapper.find('[data-testid="streaming-bubble"]');
            expect(bubble.exists()).toBe(true);
            // Should be left-aligned like assistant messages
            expect(bubble.element.closest('.justify-start')).toBeTruthy();
        });

        it('does not show empty state when streaming with no persisted messages', () => {
            const store = useConversationsStore();
            store.messages = [];
            store.streaming = true;
            store.streamingContent = '';

            const wrapper = mountThread();
            // Should NOT show the empty thread state when we're actively streaming
            expect(wrapper.find('[data-testid="empty-thread"]').exists()).toBe(false);
        });

        it('shows tool-use indicators during streaming', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Show me auth', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.streaming = true;
            store.activeToolCalls = [
                { id: 'tc-1', tool: 'BrowseRepoTree', input: { path: 'src/' } },
            ];

            const wrapper = mountThread();
            expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(true);
            expect(wrapper.text()).toContain('Browsing');
        });

        it('hides tool-use indicators when not streaming', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.streaming = false;
            store.activeToolCalls = [];

            const wrapper = mountThread();
            expect(wrapper.find('[data-testid="tool-use-indicators"]').exists()).toBe(false);
        });
    });

    describe('pinned task bar integration (T69)', () => {
        it('renders PinnedTaskBar when activeTasksForConversation has tasks', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.trackTask({
                task_id: 42,
                status: 'running',
                type: 'feature_dev',
                title: 'Payment feature',
                pipeline_id: 100,
                pipeline_status: 'running',
                started_at: '2026-02-15T12:00:00Z',
                project_id: 1,
                conversation_id: 'conv-1',
            });

            const wrapper = mountThread();
            expect(wrapper.findComponent(PinnedTaskBar).exists()).toBe(true);
        });

        it('hides PinnedTaskBar when no active tasks', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];

            const wrapper = mountThread();
            expect(wrapper.findComponent(PinnedTaskBar).exists()).toBe(false);
        });

        it('passes active tasks as prop to PinnedTaskBar', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.trackTask({
                task_id: 42,
                status: 'running',
                type: 'feature_dev',
                title: 'Test Task',
                pipeline_id: 100,
                pipeline_status: 'running',
                started_at: '2026-02-15T12:00:00Z',
                project_id: 1,
                conversation_id: 'conv-1',
            });

            const wrapper = mountThread();
            const bar = wrapper.findComponent(PinnedTaskBar);
            expect(bar.props('tasks')).toHaveLength(1);
            expect(bar.props('tasks')[0].task_id).toBe(42);
        });
    });

    describe('result card integration (T70)', () => {
        it('renders ResultCard for completed results in the conversation', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00Z' },
            ];
            store.completedResults.push({
                task_id: 42,
                status: 'completed',
                type: 'feature_dev',
                title: 'Add payment',
                mr_iid: 123,
                issue_iid: null,
                result_summary: 'Created MR',
                error_reason: null,
                result_data: { branch: 'ai/test', target_branch: 'main', files_changed: [] },
                conversation_id: 'conv-1',
                project_id: 1,
                gitlab_url: '',
            });

            const wrapper = mountThread();
            expect(wrapper.findComponent(ResultCard).exists()).toBe(true);
        });

        it('does not render ResultCard when no completed results', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00Z' },
            ];

            const wrapper = mountThread();
            expect(wrapper.findComponent(ResultCard).exists()).toBe(false);
        });

        it('does not render ResultCard for other conversations', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00Z' },
            ];
            store.completedResults.push({
                task_id: 42,
                status: 'completed',
                type: 'feature_dev',
                title: 'Add payment',
                mr_iid: 123,
                issue_iid: null,
                result_summary: 'Created MR',
                error_reason: null,
                result_data: {},
                conversation_id: 'conv-2',
                project_id: 1,
                gitlab_url: '',
            });

            const wrapper = mountThread();
            expect(wrapper.findComponent(ResultCard).exists()).toBe(false);
        });

        it('passes correct props to ResultCard', () => {
            const store = useConversationsStore();
            store.selectedId = 'conv-1';
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00Z' },
            ];
            store.completedResults.push({
                task_id: 42,
                status: 'completed',
                type: 'feature_dev',
                title: 'Add payment',
                mr_iid: 123,
                issue_iid: null,
                result_summary: 'Created MR',
                error_reason: null,
                result_data: { branch: 'ai/test', target_branch: 'main', files_changed: [{ path: 'foo.php', action: 'created', summary: 'New file' }] },
                conversation_id: 'conv-1',
                project_id: 1,
                gitlab_url: 'https://gitlab.example.com/project',
            });

            const wrapper = mountThread();
            const card = wrapper.findComponent(ResultCard);
            const props = card.props('result');
            expect(props.task_id).toBe(42);
            expect(props.status).toBe('completed');
            expect(props.mr_iid).toBe(123);
            expect(props.branch).toBe('ai/test');
        });
    });

    describe('action preview card (T68)', () => {
        it('shows ActionPreviewCard when pendingAction is set', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Create an issue', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.pendingAction = {
                id: 'preview-1',
                action_type: 'create_issue',
                project_id: 42,
                title: 'Test Issue',
                description: 'A test issue description',
            };

            const wrapper = mountThread();
            expect(wrapper.findComponent(ActionPreviewCard).exists()).toBe(true);
            expect(wrapper.find('[data-testid="action-preview-card"]').exists()).toBe(true);
        });

        it('hides ActionPreviewCard when pendingAction is null', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.pendingAction = null;

            const wrapper = mountThread();
            expect(wrapper.findComponent(ActionPreviewCard).exists()).toBe(false);
        });

        it('passes action data to ActionPreviewCard', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Implement feature', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            const action = {
                id: 'preview-1',
                action_type: 'implement_feature',
                project_id: 42,
                title: 'Payment Integration',
                description: 'Implement Stripe',
                branch_name: 'ai/payment',
            };
            store.pendingAction = action;

            const wrapper = mountThread();
            const card = wrapper.findComponent(ActionPreviewCard);
            expect(card.props('action')).toEqual(action);
        });

        it('disables composer when pendingAction is set', () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.pendingAction = {
                id: 'preview-1',
                action_type: 'create_issue',
                project_id: 42,
                title: 'Test',
                description: 'Test',
            };

            const wrapper = mountThread();
            const composer = wrapper.findComponent(MessageComposer);
            expect(composer.props('disabled')).toBe(true);
        });

        it('calls confirmAction on card confirm event', async () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Create issue', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.pendingAction = {
                id: 'preview-1',
                action_type: 'create_issue',
                project_id: 42,
                title: 'Test Issue',
                description: 'Test',
            };
            const confirmSpy = vi.spyOn(store, 'confirmAction').mockResolvedValue();

            const wrapper = mountThread();
            await wrapper.find('[data-testid="confirm-btn"]').trigger('click');

            expect(confirmSpy).toHaveBeenCalled();
        });

        it('calls cancelAction on card cancel event', async () => {
            const store = useConversationsStore();
            store.messages = [
                { id: 'msg-1', role: 'user', content: 'Create issue', created_at: '2026-02-15T12:00:00+00:00' },
            ];
            store.pendingAction = {
                id: 'preview-1',
                action_type: 'create_issue',
                project_id: 42,
                title: 'Test Issue',
                description: 'Test',
            };
            const cancelSpy = vi.spyOn(store, 'cancelAction').mockReturnValue();

            const wrapper = mountThread();
            await wrapper.find('[data-testid="cancel-btn"]').trigger('click');

            expect(cancelSpy).toHaveBeenCalled();
        });
    });
});
