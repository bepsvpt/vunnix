import { z } from 'zod';

// --- Enum schemas (matching PHP string-backed enums) ---

export const TaskStatusSchema = z.enum(['received', 'queued', 'running', 'completed', 'failed', 'superseded']);
export const TaskTypeSchema = z.enum(['code_review', 'issue_discussion', 'feature_dev', 'ui_adjustment', 'prd_creation', 'security_audit', 'deep_analysis']);
export const TaskPrioritySchema = z.enum(['high', 'normal', 'low']);
export const TaskOriginSchema = z.enum(['webhook', 'conversation']);
export const ReviewStrategySchema = z.enum(['frontend-review', 'backend-review', 'mixed-review', 'security-audit']);

// --- Shared schemas ---

export const ProjectRefSchema = z.object({
    id: z.number(),
    name: z.string(),
});

export const PaginatedMetaSchema = z.object({
    path: z.string(),
    per_page: z.number(),
    next_cursor: z.string().nullable(),
    prev_cursor: z.string().nullable(),
});

export function PaginatedResponseSchema<T extends z.ZodTypeAny>(itemSchema: T) {
    return z.object({
        data: z.array(itemSchema),
        meta: PaginatedMetaSchema,
    });
}

export const ApiErrorSchema = z.object({
    message: z.string(),
    errors: z.record(z.array(z.string())).optional(),
});

// --- Resource schemas (matching Laravel API Resources) ---

export const UserProjectSchema = z.object({
    id: z.number(),
    gitlab_project_id: z.number(),
    name: z.string(),
    slug: z.string(),
    roles: z.array(z.string()),
    permissions: z.array(z.string()),
});

export const UserSchema = z.object({
    id: z.number(),
    name: z.string(),
    email: z.string(),
    username: z.string(),
    avatar_url: z.string().nullable(),
    projects: z.array(UserProjectSchema),
});

export const MessageSchema = z.object({
    id: z.string(),
    conversation_id: z.string(),
    user_id: z.number(),
    role: z.string(),
    content: z.string(),
    tool_calls: z.unknown().nullable(),
    tool_results: z.unknown().nullable(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export const ConversationLastMessageSchema = z.object({
    content: z.string(),
    role: z.string(),
    created_at: z.string().nullable(),
});

export const ConversationSchema = z.object({
    id: z.string(),
    title: z.string(),
    project_id: z.number(),
    user_id: z.number(),
    archived_at: z.string().nullable(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
    last_message: ConversationLastMessageSchema.nullable().optional(),
    projects: z.array(ProjectRefSchema).optional(),
});

export const ConversationDetailSchema = z.object({
    id: z.string(),
    title: z.string(),
    project_id: z.number(),
    user_id: z.number(),
    archived_at: z.string().nullable(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
    messages: z.array(MessageSchema).optional(),
    projects: z.array(ProjectRefSchema).optional(),
});

export const ActivitySchema = z.object({
    task_id: z.number(),
    type: TaskTypeSchema,
    status: TaskStatusSchema,
    project_id: z.number(),
    project_name: z.string(),
    summary: z.string().nullable(),
    user_name: z.string().nullable(),
    user_avatar: z.string().nullable(),
    mr_iid: z.number().nullable(),
    issue_iid: z.number().nullable(),
    conversation_id: z.string().nullable(),
    error_reason: z.string().nullable(),
    started_at: z.string().nullable(),
    completed_at: z.string().nullable(),
    created_at: z.string().nullable(),
});

export const ExternalTaskSchema = z.object({
    id: z.number(),
    type: TaskTypeSchema,
    status: TaskStatusSchema,
    priority: TaskPrioritySchema,
    project_id: z.number(),
    project_name: z.string(),
    user_name: z.string().nullable(),
    mr_iid: z.number().nullable(),
    issue_iid: z.number().nullable(),
    commit_sha: z.string().nullable(),
    result: z.record(z.unknown()).nullable().optional(),
    cost: z.number().nullable(),
    tokens_used: z.number().nullable(),
    duration_seconds: z.number().nullable(),
    error_reason: z.string().nullable(),
    retry_count: z.number(),
    prompt_version: z.record(z.unknown()).nullable(),
    started_at: z.string().nullable(),
    completed_at: z.string().nullable(),
    created_at: z.string().nullable(),
});

export const AdminProjectSchema = z.object({
    id: z.number(),
    gitlab_project_id: z.number(),
    name: z.string(),
    slug: z.string(),
    description: z.string().nullable(),
    enabled: z.boolean(),
    webhook_configured: z.boolean(),
    webhook_id: z.number().nullable(),
    recent_task_count: z.number(),
    active_conversation_count: z.number(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export const AdminRoleSchema = z.object({
    id: z.number(),
    project_id: z.number(),
    project_name: z.string(),
    name: z.string(),
    description: z.string().nullable(),
    is_default: z.boolean(),
    permissions: z.array(z.string()),
    user_count: z.number(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export const GlobalSettingSchema = z.object({
    key: z.string(),
    value: z.unknown(),
    type: z.string(),
    description: z.string().nullable(),
    bot_pat_created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export const ProjectConfigSchema = z.object({
    settings: z.record(z.unknown()),
    effective: z.record(z.unknown()),
    setting_keys: z.array(z.string()),
});

export const AuditLogSchema = z.object({
    id: z.number(),
    event_type: z.string(),
    user_id: z.number().nullable(),
    user_name: z.string().nullable(),
    project_id: z.number().nullable(),
    project_name: z.string().nullable(),
    task_id: z.number().nullable(),
    conversation_id: z.string().nullable(),
    summary: z.string(),
    properties: z.record(z.unknown()).nullable(),
    ip_address: z.string().nullable(),
    created_at: z.string().nullable(),
});

export const MemoryEntrySchema = z.object({
    id: z.number(),
    type: z.enum(['review_pattern', 'conversation_fact', 'cross_mr_pattern']),
    category: z.string().nullable(),
    content: z.record(z.unknown()),
    confidence: z.number(),
    applied_count: z.number(),
    source_task_id: z.number().nullable(),
    created_at: z.string().nullable(),
});

export const MemoryStatsSchema = z.object({
    total_entries: z.number(),
    by_type: z.record(z.number()),
    by_category: z.record(z.number()),
    average_confidence: z.number(),
    last_created_at: z.string().nullable(),
});

// --- Inferred types ---

export type User = z.infer<typeof UserSchema>;
export type UserProject = z.infer<typeof UserProjectSchema>;
export type Message = z.infer<typeof MessageSchema>;
export type Conversation = z.infer<typeof ConversationSchema>;
export type ConversationDetail = z.infer<typeof ConversationDetailSchema>;
export type ConversationLastMessage = z.infer<typeof ConversationLastMessageSchema>;
export type Activity = z.infer<typeof ActivitySchema>;
export type ExternalTask = z.infer<typeof ExternalTaskSchema>;
export type AdminProject = z.infer<typeof AdminProjectSchema>;
export type AdminRole = z.infer<typeof AdminRoleSchema>;
export type GlobalSetting = z.infer<typeof GlobalSettingSchema>;
export type ProjectConfig = z.infer<typeof ProjectConfigSchema>;
export type AuditLog = z.infer<typeof AuditLogSchema>;
export type MemoryEntry = z.infer<typeof MemoryEntrySchema>;
export type MemoryStats = z.infer<typeof MemoryStatsSchema>;
export type ProjectRef = z.infer<typeof ProjectRefSchema>;
export type ApiError = z.infer<typeof ApiErrorSchema>;
