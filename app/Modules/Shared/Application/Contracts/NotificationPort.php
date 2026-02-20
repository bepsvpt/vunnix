<?php

namespace App\Modules\Shared\Application\Contracts;

interface NotificationPort
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function send(string $type, string $message, array $context = []): bool;

    public function sendTest(string $webhookUrl, string $platform): bool;
}
