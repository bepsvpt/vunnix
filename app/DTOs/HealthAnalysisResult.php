<?php

namespace App\DTOs;

use App\Enums\HealthDimension;

readonly class HealthAnalysisResult
{
    /**
     * @param  array<array-key, mixed>  $details
     */
    public function __construct(
        public HealthDimension $dimension,
        public float $score,
        public array $details,
        public ?string $sourceRef = null,
    ) {}
}
