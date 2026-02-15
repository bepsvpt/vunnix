import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import ActivityFeedItem from './ActivityFeedItem.vue';

function makeItem(overrides = {}) {
    return {
        task_id: 1,
        type: 'code_review',
        status: 'completed',
        project_id: 10,
        project_name: 'my-project',
        summary: 'Fix login validation',
        user_name: 'Alice',
        user_avatar: 'https://example.com/avatar.png',
        mr_iid: 42,
        issue_iid: null,
        conversation_id: null,
        error_reason: null,
        started_at: '2026-02-15T09:00:00Z',
        completed_at: '2026-02-15T09:05:00Z',
        created_at: new Date().toISOString(),
        ...overrides,
    };
}

function mountItem(item) {
    return mount(ActivityFeedItem, {
        props: { item },
    });
}

describe('ActivityFeedItem', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    // -- Rendering --

    it('renders the activity item container', () => {
        const wrapper = mountItem(makeItem());
        expect(wrapper.find('[data-testid="activity-item"]').exists()).toBe(true);
    });

    it('renders project name', () => {
        const wrapper = mountItem(makeItem({ project_name: 'frontend-app' }));
        expect(wrapper.find('[data-testid="activity-project"]').text()).toBe('frontend-app');
    });

    it('renders summary text', () => {
        const wrapper = mountItem(makeItem({ summary: 'Add dark mode toggle' }));
        expect(wrapper.find('[data-testid="activity-summary"]').text()).toBe('Add dark mode toggle');
    });

    it('hides summary when null', () => {
        const wrapper = mountItem(makeItem({ summary: null }));
        expect(wrapper.find('[data-testid="activity-summary"]').exists()).toBe(false);
    });

    it('renders relative timestamp', () => {
        const wrapper = mountItem(makeItem());
        expect(wrapper.find('[data-testid="activity-timestamp"]').text()).toBeTruthy();
    });

    // -- Type icons --

    it('shows search icon for code_review', () => {
        const wrapper = mountItem(makeItem({ type: 'code_review' }));
        expect(wrapper.find('[data-testid="activity-type-icon"]').text()).toContain('\uD83D\uDD0D');
    });

    it('shows gear icon for feature_dev', () => {
        const wrapper = mountItem(makeItem({ type: 'feature_dev' }));
        expect(wrapper.find('[data-testid="activity-type-icon"]').text()).toContain('\u2699');
    });

    it('shows palette icon for ui_adjustment', () => {
        const wrapper = mountItem(makeItem({ type: 'ui_adjustment' }));
        expect(wrapper.find('[data-testid="activity-type-icon"]').text()).toContain('\uD83C\uDFA8');
    });

    it('shows clipboard icon for prd_creation', () => {
        const wrapper = mountItem(makeItem({ type: 'prd_creation' }));
        expect(wrapper.find('[data-testid="activity-type-icon"]').text()).toContain('\uD83D\uDCCB');
    });

    // -- Status badges --

    it('shows amber badge for queued status', () => {
        const wrapper = mountItem(makeItem({ status: 'queued' }));
        const badge = wrapper.find('[data-testid="activity-status-badge"]');
        expect(badge.text()).toContain('\u23F3');
        expect(badge.classes().join(' ')).toContain('bg-amber-100');
    });

    it('shows amber badge for running status', () => {
        const wrapper = mountItem(makeItem({ status: 'running' }));
        const badge = wrapper.find('[data-testid="activity-status-badge"]');
        expect(badge.text()).toContain('\u23F3');
        expect(badge.classes().join(' ')).toContain('bg-amber-100');
    });

    it('shows green badge for completed status', () => {
        const wrapper = mountItem(makeItem({ status: 'completed' }));
        const badge = wrapper.find('[data-testid="activity-status-badge"]');
        expect(badge.text()).toContain('\u2705');
        expect(badge.classes().join(' ')).toContain('bg-emerald-100');
    });

    it('shows red badge for failed status', () => {
        const wrapper = mountItem(makeItem({ status: 'failed' }));
        const badge = wrapper.find('[data-testid="activity-status-badge"]');
        expect(badge.text()).toContain('\u274C');
        expect(badge.classes().join(' ')).toContain('bg-red-100');
    });

    // -- Click-through link text --

    it('shows MR link text when mr_iid is present', () => {
        const wrapper = mountItem(makeItem({ mr_iid: 42, issue_iid: null, conversation_id: null }));
        expect(wrapper.text()).toContain('MR !42');
    });

    it('shows Issue link text when issue_iid is present', () => {
        const wrapper = mountItem(makeItem({ mr_iid: null, issue_iid: 15, conversation_id: null }));
        expect(wrapper.text()).toContain('Issue #15');
    });

    it('shows conversation link text when conversation_id is present', () => {
        const wrapper = mountItem(makeItem({ mr_iid: null, issue_iid: null, conversation_id: 'conv-abc' }));
        expect(wrapper.text()).toContain('View conversation');
    });

    it('shows no link text when no reference is present', () => {
        const wrapper = mountItem(makeItem({ mr_iid: null, issue_iid: null, conversation_id: null }));
        expect(wrapper.text()).not.toContain('MR');
        expect(wrapper.text()).not.toContain('Issue');
        expect(wrapper.text()).not.toContain('View conversation');
    });

    // -- User info --

    it('shows user name when provided', () => {
        const wrapper = mountItem(makeItem({ user_name: 'Bob' }));
        expect(wrapper.text()).toContain('Bob');
    });

    it('hides user name when null', () => {
        const wrapper = mountItem(makeItem({ user_name: null }));
        // Should not have dangling separator
        const text = wrapper.text();
        expect(text).not.toMatch(/^\s*\u00B7/); // no leading middot
    });
});
