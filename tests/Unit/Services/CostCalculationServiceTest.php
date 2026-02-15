<?php

use App\Services\CostCalculationService;

// ─── Default pricing (Claude Opus 4.6: $5/MTok input, $25/MTok output) ──

it('calculates cost using default prices', function () {
    $service = new CostCalculationService();

    // Spec example: (150000 × $5/MTok) + (30000 × $25/MTok) = $1.50
    $cost = $service->calculate(inputTokens: 150000, outputTokens: 30000);

    expect($cost)->toBe(1.5);
});

it('calculates cost for zero tokens', function () {
    $service = new CostCalculationService();

    $cost = $service->calculate(inputTokens: 0, outputTokens: 0);

    expect($cost)->toBe(0.0);
});

it('calculates cost with only input tokens', function () {
    $service = new CostCalculationService();

    // 1M input tokens × $5/MTok = $5.00
    $cost = $service->calculate(inputTokens: 1000000, outputTokens: 0);

    expect($cost)->toBe(5.0);
});

it('calculates cost with only output tokens', function () {
    $service = new CostCalculationService();

    // 1M output tokens × $25/MTok = $25.00
    $cost = $service->calculate(inputTokens: 0, outputTokens: 1000000);

    expect($cost)->toBe(25.0);
});

// ─── Custom pricing ──────────────────────────────────────────────────────

it('calculates cost with custom prices', function () {
    $service = new CostCalculationService(
        inputPricePerMTok: 3.0,
        outputPricePerMTok: 15.0,
    );

    // (150000 × $3/MTok) + (30000 × $15/MTok) = $0.45 + $0.45 = $0.90
    $cost = $service->calculate(inputTokens: 150000, outputTokens: 30000);

    expect($cost)->toBe(0.9);
});

// ─── Precision ───────────────────────────────────────────────────────────

it('rounds cost to 6 decimal places', function () {
    $service = new CostCalculationService();

    // 1 input token × $5/MTok = $0.000005
    $cost = $service->calculate(inputTokens: 1, outputTokens: 0);

    expect($cost)->toBe(0.000005);
});

it('rounds to 6 decimals for non-exact results', function () {
    $service = new CostCalculationService();

    // 3 input tokens × $5/MTok = $0.000015
    // 7 output tokens × $25/MTok = $0.000175
    // Total = $0.000190
    $cost = $service->calculate(inputTokens: 3, outputTokens: 7);

    expect($cost)->toBe(0.00019);
});

// ─── Null token handling ─────────────────────────────────────────────────

it('treats null tokens as zero', function () {
    $service = new CostCalculationService();

    $cost = $service->calculate(inputTokens: null, outputTokens: null);

    expect($cost)->toBe(0.0);
});

it('handles mixed null and valid tokens', function () {
    $service = new CostCalculationService();

    // null input + 30000 output × $25/MTok = $0.75
    $cost = $service->calculate(inputTokens: null, outputTokens: 30000);

    expect($cost)->toBe(0.75);
});
