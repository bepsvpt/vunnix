<?php

namespace App\Services\TeamChat;

use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TeamChatNotificationService
{
    private const FORMATTERS = [
        'slack' => SlackFormatter::class,
        'mattermost' => MattermostFormatter::class,
        'google_chat' => GoogleChatFormatter::class,
        'generic' => GenericFormatter::class,
    ];

    /**
     * Send a notification to the configured team chat webhook.
     *
     * Returns true if sent successfully, false if disabled/unconfigured/failed.
     *
     * @param  array<string, mixed>  $context
     */
    public function send(string $type, string $message, array $context = []): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $webhookUrl = GlobalSetting::get('team_chat_webhook_url', '');
        if (empty($webhookUrl)) {
            return false;
        }

        // Check notification category toggle
        $category = $context['category'] ?? $type;
        if (! $this->isCategoryEnabled($category)) {
            return false;
        }

        $platform = GlobalSetting::get('team_chat_platform', 'generic');
        $formatter = $this->resolveFormatter($platform);
        $payload = $formatter->format($type, $message, $context);

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);

            if ($response->failed()) {
                Log::warning('Team chat notification failed', [
                    'status' => $response->status(),
                    'type' => $type,
                    'platform' => $platform,
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Team chat notification error', [
                'error' => $e->getMessage(),
                'type' => $type,
                'platform' => $platform,
            ]);

            return false;
        }
    }

    /**
     * Send a test notification to verify webhook connectivity.
     */
    public function sendTest(string $webhookUrl, string $platform): bool
    {
        $formatter = $this->resolveFormatter($platform);
        $payload = $formatter->format('test', '✅ Vunnix webhook test — connection successful!', [
            'urgency' => 'info',
        ]);

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function isEnabled(): bool
    {
        return (bool) GlobalSetting::get('team_chat_enabled', false);
    }

    public function isCategoryEnabled(string $category): bool
    {
        $categories = GlobalSetting::get('team_chat_categories', []);

        // If no categories configured, all are enabled by default (§18.2)
        if (empty($categories)) {
            return true;
        }

        return ! empty($categories[$category]);
    }

    public function resolveFormatter(string $platform): ChatFormatterInterface
    {
        $class = self::FORMATTERS[$platform] ?? GenericFormatter::class;

        return new $class;
    }
}
