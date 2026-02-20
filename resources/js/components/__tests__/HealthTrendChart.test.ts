import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import HealthTrendChart from '../HealthTrendChart.vue';

describe('health trend chart', () => {
    it('renders trend polyline and threshold lines with data', () => {
        const wrapper = mount(HealthTrendChart, {
            props: {
                data: [
                    { score: 82, created_at: '2026-02-10T00:00:00Z' },
                    { score: 76, created_at: '2026-02-11T00:00:00Z' },
                    { score: 68, created_at: '2026-02-12T00:00:00Z' },
                ],
                warningThreshold: 70,
                criticalThreshold: 50,
            },
        });

        expect(wrapper.find('[data-testid="trend-polyline"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="warning-threshold-line"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="critical-threshold-line"]').exists()).toBe(true);
    });

    it('renders without polyline for empty data', () => {
        const wrapper = mount(HealthTrendChart, {
            props: {
                data: [],
                warningThreshold: 70,
                criticalThreshold: 50,
            },
        });

        expect(wrapper.find('[data-testid="trend-polyline"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="warning-threshold-line"]').exists()).toBe(true);
    });

    it('uses red line color when latest point is at or below critical threshold', () => {
        const wrapper = mount(HealthTrendChart, {
            props: {
                data: [
                    { score: 40, created_at: '2026-02-12T00:00:00Z' },
                ],
                warningThreshold: 70,
                criticalThreshold: 50,
            },
        });

        expect(wrapper.find('[data-testid="trend-polyline"]').attributes('stroke')).toBe('#dc2626');
    });

    it('formats null point dates as n/a and handles single-point x-axis', () => {
        const wrapper = mount(HealthTrendChart, {
            props: {
                data: [
                    { score: 65, created_at: null },
                ],
                warningThreshold: 70,
                criticalThreshold: 50,
            },
        });

        expect(wrapper.html()).toContain('n/a: 65.0');
        expect(wrapper.findAll('circle')).toHaveLength(1);
    });
});
