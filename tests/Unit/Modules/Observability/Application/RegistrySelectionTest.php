<?php

use App\Models\AlertEvent;
use App\Modules\Observability\Application\Contracts\AlertRule;
use App\Modules\Observability\Application\Registries\AlertRuleRegistry;
use App\Services\AlertEventService;
use Carbon\Carbon;

final class RegistrySelectionRuleA implements AlertRule
{
    public function key(): string
    {
        return 'a';
    }

    public function priority(): int
    {
        return 100;
    }

    public function evaluate(AlertEventService $service, Carbon $now): ?AlertEvent
    {
        return null;
    }
}

final class RegistrySelectionRuleB implements AlertRule
{
    public function key(): string
    {
        return 'b';
    }

    public function priority(): int
    {
        return 100;
    }

    public function evaluate(AlertEventService $service, Carbon $now): ?AlertEvent
    {
        return null;
    }
}

final class RegistrySelectionRuleC implements AlertRule
{
    public function key(): string
    {
        return 'c';
    }

    public function priority(): int
    {
        return 10;
    }

    public function evaluate(AlertEventService $service, Carbon $now): ?AlertEvent
    {
        return null;
    }
}

it('orders alert rules by priority then class name deterministically', function (): void {
    $registry = new AlertRuleRegistry([
        new RegistrySelectionRuleC,
        new RegistrySelectionRuleB,
        new RegistrySelectionRuleA,
    ]);

    $ordered = $registry->all();

    expect(array_map(static fn (AlertRule $rule): string => $rule::class, $ordered))
        ->toBe([
            RegistrySelectionRuleA::class,
            RegistrySelectionRuleB::class,
            RegistrySelectionRuleC::class,
        ]);
});
