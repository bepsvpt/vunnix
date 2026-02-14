<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Generates and validates task-scoped bearer tokens for executor authentication.
 *
 * Tokens are stateless HMAC-SHA256 signatures — no database lookup required.
 * Format: base64url(task_id:expiry_unix:hmac_signature)
 *
 * TTL matches the total scheduling + execution budget (D127, §19.3),
 * defaulting to 60 minutes. The executor validates token freshness on
 * execution start — if expired, it exits with `scheduling_timeout`.
 *
 * @see §20.4 Runner Result API
 */
class TaskTokenService
{
    public function __construct(
        private readonly string $appKey,
        private readonly int $budgetMinutes,
    ) {}

    /**
     * Generate a task-scoped bearer token.
     */
    public function generate(int $taskId): string
    {
        $expiry = Carbon::now()->addMinutes($this->budgetMinutes)->getTimestamp();
        $payload = "{$taskId}:{$expiry}";
        $signature = $this->sign($payload);

        return $this->base64UrlEncode("{$payload}:{$signature}");
    }

    /**
     * Validate a task-scoped bearer token.
     */
    public function validate(string $token, int $taskId): bool
    {
        $decoded = $this->base64UrlDecode($token);

        if ($decoded === false) {
            return false;
        }

        $parts = explode(':', $decoded, 3);

        if (count($parts) !== 3) {
            return false;
        }

        [$tokenTaskId, $expiry, $signature] = $parts;

        // Verify task ID matches
        if ((int) $tokenTaskId !== $taskId) {
            return false;
        }

        // Verify not expired
        if (Carbon::now()->getTimestamp() > (int) $expiry) {
            return false;
        }

        // Verify HMAC signature
        $expectedSignature = $this->sign("{$tokenTaskId}:{$expiry}");

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Compute HMAC-SHA256 signature for a payload.
     */
    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->appKey);
    }

    /**
     * URL-safe base64 encode.
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL-safe base64 decode.
     */
    private function base64UrlDecode(string $data): string|false
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), strict: true);

        return $decoded;
    }
}
