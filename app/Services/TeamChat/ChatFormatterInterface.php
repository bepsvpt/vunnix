<?php

namespace App\Services\TeamChat;

interface ChatFormatterInterface
{
    /**
     * Format a notification into the platform-specific webhook payload.
     *
     * @param  string  $type  Notification type: 'task_completed', 'task_failed', 'alert'
     * @param  string  $message  Plain text summary (always included as fallback)
     * @param  array<string, mixed>  $context  Additional data: urgency, project, links, etc.
     * @return array<string, mixed> JSON-encodable payload for HTTP POST to webhook URL
     */
    public function format(string $type, string $message, array $context = []): array;
}
