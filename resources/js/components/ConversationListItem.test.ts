import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ConversationListItem from './ConversationListItem.vue';

function makeConversation(overrides: Record<string, unknown> = {}) {
    return {
        id: 'conv-1',
        title: 'Implement auth flow',
        project_id: 1,
        user_id: 1,
        archived_at: null,
        created_at: '2026-02-15T10:00:00+00:00',
        updated_at: '2026-02-15T12:00:00+00:00',
        last_message: {
            content: 'Can you review the login component?',
            role: 'user',
            created_at: '2026-02-15T12:00:00+00:00',
        },
        ...overrides,
    };
}

describe('conversationListItem', () => {
    it('renders conversation title', () => {
        const wrapper = mount(ConversationListItem, {
            props: { conversation: makeConversation() },
        });

        expect(wrapper.find('h3').text()).toBe('Implement auth flow');
    });

    it('shows project name badge when provided', () => {
        const wrapper = mount(ConversationListItem, {
            props: {
                conversation: makeConversation(),
                projectName: 'vunnix-api',
            },
        });

        expect(wrapper.find('span.text-xs').text()).toBe('vunnix-api');
    });

    it('hides project name badge when not provided', () => {
        const wrapper = mount(ConversationListItem, {
            props: { conversation: makeConversation() },
        });

        // The badge span uses v-if="projectName", so it should not exist
        const badges = wrapper.findAll('span.shrink-0');
        expect(badges.length).toBe(0);
    });

    it('shows last message preview', () => {
        const wrapper = mount(ConversationListItem, {
            props: { conversation: makeConversation() },
        });

        expect(wrapper.find('p.text-xs').text()).toBe('Can you review the login component?');
    });

    it('shows "No messages yet" when no last_message', () => {
        const wrapper = mount(ConversationListItem, {
            props: {
                conversation: makeConversation({ last_message: null }),
            },
        });

        expect(wrapper.find('p.text-xs').text()).toBe('No messages yet');
    });

    it('emits select with conversation ID on click', async () => {
        const wrapper = mount(ConversationListItem, {
            props: { conversation: makeConversation({ id: 'conv-42' }) },
        });

        await wrapper.find('button').trigger('click');

        expect(wrapper.emitted('select')).toBeTruthy();
        expect(wrapper.emitted('select')![0]).toEqual(['conv-42']);
    });

    it('emits archive with conversation ID on archive button click', async () => {
        const wrapper = mount(ConversationListItem, {
            props: { conversation: makeConversation({ id: 'conv-42' }) },
        });

        // The archive button is the nested button inside the component
        const buttons = wrapper.findAll('button');
        const archiveBtn = buttons[buttons.length - 1]; // last button is archive
        await archiveBtn.trigger('click');

        expect(wrapper.emitted('archive')).toBeTruthy();
        expect(wrapper.emitted('archive')![0]).toEqual(['conv-42']);
        // Should NOT emit select (click.stop)
        expect(wrapper.emitted('select')).toBeFalsy();
    });

    it('shows relative time for recent updates', () => {
        const fiveMinAgo = new Date(Date.now() - 5 * 60 * 1000).toISOString();
        const wrapper = mount(ConversationListItem, {
            props: {
                conversation: makeConversation({ updated_at: fiveMinAgo }),
            },
        });

        const timeText = wrapper.find('span.text-zinc-400').text().trim();
        expect(timeText).toBe('5m ago');
    });

    it('shows "just now" for sub-minute updates', () => {
        const wrapper = mount(ConversationListItem, {
            props: {
                conversation: makeConversation({ updated_at: new Date().toISOString() }),
            },
        });

        const timeText = wrapper.find('span.text-zinc-400').text().trim();
        expect(timeText).toBe('just now');
    });

    it('shows hours for older updates', () => {
        const threeHoursAgo = new Date(Date.now() - 3 * 60 * 60 * 1000).toISOString();
        const wrapper = mount(ConversationListItem, {
            props: {
                conversation: makeConversation({ updated_at: threeHoursAgo }),
            },
        });

        const timeText = wrapper.find('span.text-zinc-400').text().trim();
        expect(timeText).toBe('3h ago');
    });

    it('falls back to locale date for old updates', () => {
        const oldDate = new Date(Date.now() - 40 * 24 * 60 * 60 * 1000);
        const wrapper = mount(ConversationListItem, {
            props: {
                conversation: makeConversation({ updated_at: oldDate.toISOString() }),
            },
        });

        const timeText = wrapper.find('span.text-zinc-400').text().trim();
        expect(timeText).toBe(oldDate.toLocaleDateString());
    });

    it('applies selected styling when isSelected is true', () => {
        const wrapper = mount(ConversationListItem, {
            props: {
                conversation: makeConversation(),
                isSelected: true,
            },
        });

        const rootButton = wrapper.find('button');
        expect(rootButton.classes()).toContain('border-l-blue-500');
        expect(rootButton.classes()).toContain('bg-zinc-50');
    });

    it('does not apply selected styling when isSelected is false', () => {
        const wrapper = mount(ConversationListItem, {
            props: {
                conversation: makeConversation(),
                isSelected: false,
            },
        });

        const rootButton = wrapper.find('button');
        expect(rootButton.classes()).not.toContain('border-l-blue-500');
        expect(rootButton.classes()).toContain('border-l-transparent');
    });

    it('shows Archive title for non-archived conversations', () => {
        const wrapper = mount(ConversationListItem, {
            props: {
                conversation: makeConversation({ archived_at: null }),
            },
        });

        const buttons = wrapper.findAll('button');
        const archiveBtn = buttons[buttons.length - 1];
        expect(archiveBtn.attributes('title')).toBe('Archive');
    });

    it('shows Unarchive title for archived conversations', () => {
        const wrapper = mount(ConversationListItem, {
            props: {
                conversation: makeConversation({ archived_at: '2026-02-15T10:00:00+00:00' }),
            },
        });

        const buttons = wrapper.findAll('button');
        const archiveBtn = buttons[buttons.length - 1];
        expect(archiveBtn.attributes('title')).toBe('Unarchive');
    });
});
