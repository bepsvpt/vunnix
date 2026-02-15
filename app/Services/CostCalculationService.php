<?php

namespace App\Services;

/**
 * Calculate AI API cost from token counts and per-MTok pricing.
 *
 * Formula: (input_tokens × input_price / 1,000,000) + (output_tokens × output_price / 1,000,000)
 *
 * Prices are in dollars per million tokens (MTok), matching Anthropic's
 * published pricing format. Defaults to Claude Opus 4.6 pricing (Feb 2026).
 *
 * @see §10.2 AI API Cost Estimates
 * @see §5.4 Metrics — Cost metrics
 */
class CostCalculationService
{
    private const TOKENS_PER_MTOK = 1_000_000;

    public function __construct(
        private readonly float $inputPricePerMTok = 5.0,
        private readonly float $outputPricePerMTok = 25.0,
    ) {}

    /**
     * Calculate cost for a task given its token counts.
     *
     * @return float Cost in dollars, rounded to 6 decimal places
     */
    public function calculate(?int $inputTokens, ?int $outputTokens): float
    {
        $input = ($inputTokens ?? 0) * $this->inputPricePerMTok / self::TOKENS_PER_MTOK;
        $output = ($outputTokens ?? 0) * $this->outputPricePerMTok / self::TOKENS_PER_MTOK;

        return round($input + $output, 6);
    }
}
