<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogService
{
    /**
     * @param  array<int, mixed>  $toolCalls
     */
    public function logConversationTurn(
        int $userId,
        string $conversationId,
        string $userMessage,
        string $aiResponse,
        array $toolCalls,
        int $tokensUsed,
        string $model,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'conversation_turn',
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'summary' => 'Conversation turn recorded',
            'properties' => [
                'user_message' => $userMessage,
                'ai_response' => $aiResponse,
                'tool_calls' => $toolCalls,
                'tokens_used' => $tokensUsed,
                'model' => $model,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $gitlabContext
     */
    public function logTaskExecution(
        int $taskId,
        ?int $userId,
        int $projectId,
        string $taskType,
        array $gitlabContext,
        string $promptSent,
        string $aiResponse,
        int $tokensUsed,
        float $cost,
        int $durationSeconds,
        string $resultStatus,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'task_execution',
            'user_id' => $userId,
            'task_id' => $taskId,
            'project_id' => $projectId,
            'summary' => "Task execution completed: {$taskType}",
            'properties' => [
                'task_type' => $taskType,
                'gitlab_context' => $gitlabContext,
                'prompt_sent' => $promptSent,
                'ai_response' => $aiResponse,
                'tokens_used' => $tokensUsed,
                'cost' => $cost,
                'duration_seconds' => $durationSeconds,
                'result_status' => $resultStatus,
            ],
        ]);
    }

    public function logActionDispatch(
        int $userId,
        string $conversationId,
        string $actionType,
        int $projectId,
        ?string $gitlabArtifactUrl = null,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'action_dispatch',
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'project_id' => $projectId,
            'summary' => "Action dispatched: {$actionType}",
            'properties' => [
                'action_type' => $actionType,
                'gitlab_artifact_url' => $gitlabArtifactUrl,
            ],
        ]);
    }

    public function logConfigurationChange(
        int $userId,
        string $key,
        mixed $oldValue,
        mixed $newValue,
        ?int $projectId = null,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'configuration_change',
            'user_id' => $userId,
            'project_id' => $projectId,
            'summary' => "Configuration changed: {$key}",
            'properties' => [
                'key' => $key,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $relevantIds
     */
    public function logWebhookReceived(
        int $projectId,
        string $eventType,
        array $relevantIds,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'webhook_received',
            'project_id' => $projectId,
            'summary' => "Webhook received: {$eventType}",
            'properties' => [
                'gitlab_event_type' => $eventType,
                'relevant_ids' => $relevantIds,
            ],
        ]);
    }

    public function logAuthEvent(
        int $userId,
        string $action,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'auth_event',
            'user_id' => $userId,
            'summary' => "User {$action}",
            'properties' => [
                'action' => $action,
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
