<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\User;
use Carbon\Carbon;

class ApiKeyService
{
    /**
     * Generate a new API key for a user.
     *
     * Returns an array with ['api_key' => ApiKey, 'plaintext' => string].
     * The plaintext is shown once at creation and cannot be retrieved afterward (D152).
     */
    public function generate(User $user, string $name, ?Carbon $expiresAt = null): array
    {
        $plaintext = bin2hex(random_bytes(32)); // 64-char hex string
        $hash = hash('sha256', $plaintext);

        $apiKey = $user->apiKeys()->create([
            'name' => $name,
            'key' => $hash,
            'expires_at' => $expiresAt,
            'revoked' => false,
        ]);

        return [
            'api_key' => $apiKey,
            'plaintext' => $plaintext,
        ];
    }

    /**
     * Resolve a user from a plaintext API key.
     *
     * Returns null if the key is invalid, revoked, or expired.
     * Records usage (IP + timestamp) on successful resolve.
     */
    public function resolveUser(string $plaintext, ?string $ip = null): ?User
    {
        $hash = hash('sha256', $plaintext);

        $apiKey = ApiKey::active()
            ->where('key', $hash)
            ->first();

        if (! $apiKey) {
            return null;
        }

        $apiKey->recordUsage($ip ?? 'unknown');

        return $apiKey->user;
    }

    /**
     * Revoke an API key.
     */
    public function revoke(ApiKey $apiKey): void
    {
        $apiKey->update([
            'revoked' => true,
            'revoked_at' => now(),
        ]);
    }
}
