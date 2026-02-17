<?php

namespace App\Services;

use App\Models\AlertEvent;
use App\Models\GlobalSetting;
use App\Services\TeamChat\TeamChatNotificationService;
use Carbon\Carbon;

class BotPatRotationService
{
    private const ALERT_TYPE = 'bot_pat_rotation';

    private const THRESHOLD_MONTHS = 5.5;

    private const REPEAT_INTERVAL_DAYS = 7;

    public function __construct(
        private readonly TeamChatNotificationService $teamChat,
    ) {}

    /**
     * Evaluate PAT rotation status. Returns an alert if one was created, null otherwise.
     *
     * Logic per D144:
     * - At 5.5 months (2 weeks before 6-month expiry threshold), trigger alert
     * - Repeat weekly until acknowledged
     * - Acknowledged = alert resolved; won't re-fire for 7 days
     */
    public function evaluate(?Carbon $now = null): ?AlertEvent
    {
        $now ??= now();

        $patCreatedAt = $this->getPatCreatedAt();

        if (! $patCreatedAt) {
            // No PAT creation date configured — nothing to check
            return null;
        }

        $ageInDays = (int) $patCreatedAt->diffInDays($now);
        $thresholdDays = (int) round(self::THRESHOLD_MONTHS * 30.44); // ~167 days

        if ($ageInDays < $thresholdDays) {
            // PAT is not yet old enough to warrant a reminder
            return null;
        }

        // Check if an active alert already exists (not yet acknowledged)
        $activeAlert = AlertEvent::active()->ofType(self::ALERT_TYPE)->first();
        if ($activeAlert) {
            return null; // Already alerting, wait for acknowledgement
        }

        // Check if an alert was acknowledged recently (within repeat interval)
        $recentlyAcknowledged = AlertEvent::ofType(self::ALERT_TYPE)
            ->where('status', 'resolved')
            ->where('resolved_at', '>=', $now->copy()->subDays(self::REPEAT_INTERVAL_DAYS))
            ->exists();

        if ($recentlyAcknowledged) {
            return null; // Acknowledged within the last 7 days, don't re-alert yet
        }

        // Create new alert
        $ageMonths = round($ageInDays / 30.44, 1);
        $alert = AlertEvent::create([
            'alert_type' => self::ALERT_TYPE,
            'status' => 'active',
            'severity' => 'high',
            'message' => "Bot PAT rotation needed — PAT is {$ageMonths} months old. "
                .'GitLab PATs expire after 12 months. Rotate now to prevent service disruption. '
                .'Go to Admin → Settings to update the PAT and creation date.',
            'context' => [
                'pat_created_at' => $patCreatedAt->toIso8601String(),
                'age_days' => $ageInDays,
                'age_months' => $ageMonths,
            ],
            'detected_at' => $now,
        ]);

        $this->notifyPatRotation($alert);

        return $alert;
    }

    /**
     * Acknowledge a PAT rotation alert (stops weekly repeat for 7 days).
     */
    public function acknowledge(AlertEvent $alert): AlertEvent
    {
        $alert->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        return $alert->fresh() ?? $alert;
    }

    /**
     * Send team chat notification for PAT rotation reminder.
     */
    private function notifyPatRotation(AlertEvent $alert): void
    {
        $this->teamChat->send('alert', $alert->message, [
            'category' => 'alert',
            'urgency' => 'high',
            'alert_type' => self::ALERT_TYPE,
        ]);

        $alert->update(['notified_at' => now()]);
    }

    /**
     * Get the PAT creation date from global settings.
     */
    private function getPatCreatedAt(): ?Carbon
    {
        $setting = GlobalSetting::where('key', 'bot_pat_created_at')->first();

        return $setting?->bot_pat_created_at;
    }
}
