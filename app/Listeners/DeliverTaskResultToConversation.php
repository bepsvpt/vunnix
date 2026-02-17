<?php

namespace App\Listeners;

use App\Events\TaskStatusChanged;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DeliverTaskResultToConversation
{
    public function handle(TaskStatusChanged $event): void
    {
        $task = $event->task;

        // Only for terminal tasks with a conversation
        if (! $task->isTerminal() || $task->conversation_id === null) {
            return;
        }

        // Guard against missing table in SQLite test environment
        // (agent_conversation_messages is created by Laravel AI SDK migration with PostgreSQL features)
        if (! Schema::hasTable('agent_conversation_messages')) {
            return;
        }

        $content = $this->buildResultContent($task);

        Message::create([
            'conversation_id' => $task->conversation_id,
            'role' => 'system',
            'content' => $content,
            'user_id' => 0,
            'agent' => '',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
        ]);

        Log::info('DeliverTaskResultToConversation: system message added', [
            'task_id' => $task->id,
            'conversation_id' => $task->conversation_id,
        ]);
    }

    private function buildResultContent(\App\Models\Task $task): string
    {
        return match ($task->type) {
            \App\Enums\TaskType::DeepAnalysis => $this->buildDeepAnalysisContent($task),
            \App\Enums\TaskType::IssueDiscussion => $this->buildIssueDiscussionContent($task),
            \App\Enums\TaskType::PrdCreation => $this->buildPrdCreationContent($task),
            default => $this->buildGenericResultContent($task),
        };
    }

    private function buildGenericResultContent(\App\Models\Task $task): string
    {
        $statusText = $task->status->value;
        $title = $task->result['title'] ?? $task->result['mr_title'] ?? 'Task';
        $result = $task->result ?? [];

        $parts = ["[System: Task result delivered] Task #{$task->id} \"{$title}\" {$statusText}."];

        // MR reference
        if ($task->mr_iid !== null) {
            $parts[] = "MR !{$task->mr_iid}";
        }

        // Branch info
        $branch = $result['branch'] ?? null;
        if ($branch) {
            $targetBranch = $result['target_branch'] ?? 'main';
            $parts[] = "Branch: {$branch} → {$targetBranch}";
        }

        // Files changed count
        $filesChanged = $result['files_changed'] ?? [];
        if (count($filesChanged) > 0) {
            $count = count($filesChanged);
            $parts[] = "{$count} ".($count === 1 ? 'file' : 'files').' changed';
        }

        // Implementation notes
        $notes = $result['notes'] ?? null;
        if (is_string($notes) && $notes !== '') {
            $parts[] = "Notes: {$notes}";
        }

        return implode(' | ', $parts);
    }

    private function buildIssueDiscussionContent(\App\Models\Task $task): string
    {
        $result = $task->result ?? [];
        $title = $result['title'] ?? $result['question'] ?? 'Issue Discussion';
        $statusText = $task->status->value;

        $parts = ["[System: Task result delivered] Task #{$task->id} \"{$title}\" {$statusText}."];

        $response = $result['response'] ?? null;
        if (is_string($response) && $response !== '') {
            $parts[] = "\n\n## Response\n\n{$response}";
        }

        $references = $result['references'] ?? [];
        if (is_array($references) && $references !== []) {
            $refLines = ["\n\n## References"];
            foreach ($references as $ref) {
                $file = $ref['file'] ?? '';
                $line = $ref['line'] ?? 0;
                $context = $ref['context'] ?? '';
                $refLines[] = "- `{$file}:{$line}` — {$context}";
            }
            $parts[] = implode("\n", $refLines);
        }

        return implode('', $parts);
    }

    private function buildPrdCreationContent(\App\Models\Task $task): string
    {
        $result = $task->result ?? [];
        $title = $result['title'] ?? 'Issue';
        $statusText = $task->status->value;

        $parts = ["[System: Task result delivered] Task #{$task->id} \"{$title}\" {$statusText}."];

        if ($task->issue_iid !== null) {
            $parts[] = " Issue #{$task->issue_iid} created.";
        }

        $url = $result['gitlab_issue_url'] ?? null;
        if (is_string($url) && $url !== '') {
            $parts[] = " URL: {$url}";
        }

        return implode('', $parts);
    }

    private function buildDeepAnalysisContent(\App\Models\Task $task): string
    {
        $result = $task->result ?? [];
        $title = $result['title'] ?? 'Deep Analysis';
        $statusText = $task->status->value;

        $parts = ["[System: Task result delivered] Task #{$task->id} \"{$title}\" {$statusText}."];

        // Include the full analysis markdown for the AI to reference in conversation
        $analysis = $result['analysis'] ?? null;
        if (is_string($analysis) && $analysis !== '') {
            $parts[] = "\n\n## Analysis Result\n\n{$analysis}";
        }

        // Include key findings as a structured summary
        $findings = $result['key_findings'] ?? [];
        if (is_array($findings) && $findings !== []) {
            $findingLines = ["\n\n## Key Findings"];
            foreach ($findings as $finding) {
                $severity = $finding['severity'] ?? 'info';
                $findingTitle = $finding['title'] ?? 'Finding';
                $description = $finding['description'] ?? '';
                $findingLines[] = "- **[{$severity}] {$findingTitle}**: {$description}";
            }
            $parts[] = implode("\n", $findingLines);
        }

        return implode('', $parts);
    }
}
