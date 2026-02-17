export type TaskStatus = 'received' | 'queued' | 'running' | 'completed' | 'failed' | 'superseded';

export type TaskType = 'code_review' | 'issue_discussion' | 'feature_dev' | 'ui_adjustment' | 'prd_creation' | 'security_audit' | 'deep_analysis';

export type TaskPriority = 'high' | 'normal' | 'low';

export type TaskOrigin = 'webhook' | 'conversation';

export type ReviewStrategy = 'frontend-review' | 'backend-review' | 'mixed-review' | 'security-audit';

export const TERMINAL_STATUSES: TaskStatus[] = ['completed', 'failed', 'superseded'];

export function isTerminalStatus(status: TaskStatus): boolean {
    return TERMINAL_STATUSES.includes(status);
}
