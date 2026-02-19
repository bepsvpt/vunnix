import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import HealthAlertCard from './HealthAlertCard.vue';

describe('health alert card', () => {
    it('renders alert severity and message', () => {
        const wrapper = mount(HealthAlertCard, {
            props: {
                alert: {
                    id: 1,
                    alert_type: 'health_coverage_decline',
                    status: 'active',
                    severity: 'warning',
                    message: 'Coverage dropped below threshold.',
                    context: {},
                    detected_at: '2026-02-19T05:00:00Z',
                    resolved_at: null,
                    created_at: '2026-02-19T05:00:00Z',
                    updated_at: '2026-02-19T05:00:00Z',
                },
            },
        });

        expect(wrapper.find('[data-testid="health-alert-message"]').text()).toContain('Coverage dropped below threshold');
        expect(wrapper.text()).toContain('warning');
    });

    it('renders gitlab issue link when available', () => {
        const wrapper = mount(HealthAlertCard, {
            props: {
                alert: {
                    id: 2,
                    alert_type: 'health_vulnerability_found',
                    status: 'active',
                    severity: 'critical',
                    message: 'Dependency vulnerabilities found.',
                    context: { gitlab_issue_url: 'https://gitlab.example.com/group/project/-/issues/42' },
                    detected_at: '2026-02-19T05:00:00Z',
                    resolved_at: null,
                    created_at: '2026-02-19T05:00:00Z',
                    updated_at: '2026-02-19T05:00:00Z',
                },
            },
        });

        const link = wrapper.find('[data-testid="health-alert-issue-link"]');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toContain('/issues/42');
    });
});
