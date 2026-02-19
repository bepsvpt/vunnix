<?php

namespace App\Enums;

enum HealthDimension: string
{
    case Coverage = 'coverage';
    case Dependency = 'dependency';
    case Complexity = 'complexity';

    public function label(): string
    {
        return match ($this) {
            self::Coverage => 'Test Coverage',
            self::Dependency => 'Dependency Health',
            self::Complexity => 'Code Complexity',
        };
    }

    public function configKey(): string
    {
        return match ($this) {
            self::Coverage => 'health.coverage_tracking',
            self::Dependency => 'health.dependency_scanning',
            self::Complexity => 'health.complexity_tracking',
        };
    }

    public function defaultWarningThreshold(): float
    {
        return match ($this) {
            self::Coverage => (float) config('health.thresholds.coverage.warning', 70),
            self::Dependency => (float) config('health.thresholds.dependency.warning', 1),
            self::Complexity => (float) config('health.thresholds.complexity.warning', 50),
        };
    }

    public function defaultCriticalThreshold(): float
    {
        return match ($this) {
            self::Coverage => (float) config('health.thresholds.coverage.critical', 50),
            self::Dependency => (float) config('health.thresholds.dependency.critical', 3),
            self::Complexity => (float) config('health.thresholds.complexity.critical', 30),
        };
    }

    public function alertType(): string
    {
        return match ($this) {
            self::Coverage => 'health_coverage_decline',
            self::Dependency => 'health_vulnerability_found',
            self::Complexity => 'health_complexity_spike',
        };
    }
}
