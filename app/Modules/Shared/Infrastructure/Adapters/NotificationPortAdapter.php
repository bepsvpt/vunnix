<?php

namespace App\Modules\Shared\Infrastructure\Adapters;

use App\Modules\Shared\Application\Contracts\NotificationPort;
use App\Services\TeamChat\TeamChatNotificationService;

class NotificationPortAdapter implements NotificationPort
{
    public function __construct(
        private readonly TeamChatNotificationService $notificationService,
    ) {}

    public function send(string $type, string $message, array $context = []): bool
    {
        return $this->notificationService->send($type, $message, $context);
    }

    public function sendTest(string $webhookUrl, string $platform): bool
    {
        return $this->notificationService->sendTest($webhookUrl, $platform);
    }
}
