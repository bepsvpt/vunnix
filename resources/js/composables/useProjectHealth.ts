import type { infer as ZodInfer } from 'zod';
import type {
    HealthAlert,
    HealthDimension,
    HealthSnapshot,
    HealthSummary,
} from '@/types';
import axios from 'axios';
import {
    HealthAlertSchema,
    HealthSnapshotSchema,
    HealthSummarySchema,
    PaginatedMetaSchema,
} from '@/types';

type PaginatedMeta = ZodInfer<typeof PaginatedMetaSchema>;

const TrendsResponseSchema = HealthSnapshotSchema.array();
const AlertsResponseSchema = HealthAlertSchema.array();

interface DateRange {
    from?: string;
    to?: string;
}

export function useProjectHealth(projectId: number) {
    async function fetchSummary(): Promise<HealthSummary> {
        const response = await axios.get(`/api/v1/projects/${projectId}/health/summary`);
        return HealthSummarySchema.parse(response.data.data);
    }

    async function fetchTrends(dimension: HealthDimension, range: DateRange = {}): Promise<HealthSnapshot[]> {
        const response = await axios.get(`/api/v1/projects/${projectId}/health/trends`, {
            params: {
                dimension,
                from: range.from,
                to: range.to,
            },
        });

        return TrendsResponseSchema.parse(response.data.data);
    }

    async function fetchAlerts(): Promise<{ data: HealthAlert[]; meta: PaginatedMeta }> {
        const response = await axios.get(`/api/v1/projects/${projectId}/health/alerts`);
        return {
            data: AlertsResponseSchema.parse(response.data.data),
            meta: PaginatedMetaSchema.parse(response.data.meta),
        };
    }

    return {
        fetchSummary,
        fetchTrends,
        fetchAlerts,
    };
}
