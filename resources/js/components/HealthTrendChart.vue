<script setup lang="ts">
import { computed } from 'vue';

interface TrendPoint {
    score: number;
    created_at: string | null;
}

interface Props {
    data: TrendPoint[];
    warningThreshold: number;
    criticalThreshold: number;
}

const props = defineProps<Props>();

const width = 720;
const height = 260;
const padding = 28;

const plotWidth = width - padding * 2;
const plotHeight = height - padding * 2;

const points = computed(() => props.data.filter(point => Number.isFinite(point.score)));

function yForScore(score: number): number {
    const clamped = Math.max(0, Math.min(100, score));
    return padding + (100 - clamped) * (plotHeight / 100);
}

function xForIndex(index: number): number {
    if (points.value.length <= 1) {
        return padding;
    }

    return padding + (index / (points.value.length - 1)) * plotWidth;
}

const polylinePoints = computed(() => points.value
    .map((point, index) => `${xForIndex(index)},${yForScore(point.score)}`)
    .join(' '));

const lineColor = computed(() => {
    const latest = points.value[points.value.length - 1];
    if (!latest) {
        return '#0f766e';
    }
    if (latest.score <= props.criticalThreshold) {
        return '#dc2626';
    }
    if (latest.score <= props.warningThreshold) {
        return '#ca8a04';
    }

    return '#16a34a';
});

function displayDate(value: string | null): string {
    if (!value) {
        return 'n/a';
    }

    return new Date(value).toLocaleDateString();
}
</script>

<template>
    <div data-testid="health-trend-chart">
        <svg
            :viewBox="`0 0 ${width} ${height}`"
            class="w-full h-[260px]"
            role="img"
            aria-label="Health trend chart"
        >
            <line
                :x1="padding"
                :y1="yForScore(warningThreshold)"
                :x2="width - padding"
                :y2="yForScore(warningThreshold)"
                stroke="#f59e0b"
                stroke-dasharray="5 4"
                stroke-width="1.5"
                data-testid="warning-threshold-line"
            />
            <line
                :x1="padding"
                :y1="yForScore(criticalThreshold)"
                :x2="width - padding"
                :y2="yForScore(criticalThreshold)"
                stroke="#ef4444"
                stroke-dasharray="5 4"
                stroke-width="1.5"
                data-testid="critical-threshold-line"
            />

            <polyline
                v-if="points.length > 0"
                :points="polylinePoints"
                fill="none"
                :stroke="lineColor"
                stroke-width="3"
                stroke-linecap="round"
                stroke-linejoin="round"
                data-testid="trend-polyline"
            />

            <circle
                v-for="(point, index) in points"
                :key="`${point.created_at}-${index}`"
                :cx="xForIndex(index)"
                :cy="yForScore(point.score)"
                r="4"
                :fill="lineColor"
            >
                <title>{{ `${displayDate(point.created_at)}: ${point.score.toFixed(1)}` }}</title>
            </circle>
        </svg>
    </div>
</template>
