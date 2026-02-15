<?php

namespace App\Listeners;

use App\Events\TaskStatusChanged;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

class DeliverTaskResultToConversation
{
    public function handle(TaskStatusChanged $event): void
    {
        $task = $event->task;

        // Only for terminal tasks with a conversation
        if (! $task->isTerminal() || $task->conversation_id === null) {
            return;
        }

        $statusText = $task->status->value;
        $title = $task->result['title'] ?? $task->result['mr_title'] ?? 'Task';

        Message::create([
            'conversation_id' => $task->conversation_id,
            'role' => 'system',
            'content' => "[System: Task result delivered] Task #{$task->id} \"{$title}\" {$statusText}.",
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
}
