<?php

use App\Enums\HealthDimension;

it('returns labels and config keys', function (): void {
    expect(HealthDimension::Coverage->label())->toBe('Test Coverage');
    expect(HealthDimension::Coverage->configKey())->toBe('health.coverage_tracking');
    expect(HealthDimension::Dependency->configKey())->toBe('health.dependency_scanning');
    expect(HealthDimension::Complexity->configKey())->toBe('health.complexity_tracking');
});

it('returns health alert types', function (): void {
    expect(HealthDimension::Coverage->alertType())->toBe('health_coverage_decline');
    expect(HealthDimension::Dependency->alertType())->toBe('health_vulnerability_found');
    expect(HealthDimension::Complexity->alertType())->toBe('health_complexity_spike');
});
