<?php

namespace App\Enums;

enum ReviewStrategy: string
{
    case FrontendReview = 'frontend-review';
    case BackendReview = 'backend-review';
    case MixedReview = 'mixed-review';
    case SecurityAudit = 'security-audit';

    /**
     * Get the skill file name(s) activated by this strategy.
     *
     * @return array<int, string>
     */
    public function skills(): array
    {
        return match ($this) {
            self::FrontendReview => ['frontend-review'],
            self::BackendReview => ['backend-review'],
            self::MixedReview => ['frontend-review', 'backend-review'],
            self::SecurityAudit => ['security-audit'],
        };
    }
}
