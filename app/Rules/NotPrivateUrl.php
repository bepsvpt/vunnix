<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NotPrivateUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return;
        }

        $normalizedHost = strtolower(trim($host, '[]'));
        if ($normalizedHost === 'localhost') {
            $fail("The {$attribute} must not target a private or internal address.");

            return;
        }

        $resolvedIps = $this->resolveHostIps($normalizedHost);

        foreach ($resolvedIps as $ip) {
            if ($this->isPrivateOrReservedIp($ip)) {
                $fail("The {$attribute} must not target a private or internal address.");

                return;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = gethostbynamel($host);

        if ($ips === false) {
            return [];
        }

        return array_values(array_unique($ips));
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        if ($ip === '169.254.169.254') {
            return true;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
