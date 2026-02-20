<?php

use App\Models\AlertEvent;
use App\Modules\Observability\Application\Contracts\AlertRule;
use App\Modules\Observability\Application\Registries\AlertRuleRegistry;
use App\Modules\Observability\Application\Rules\AlertMethodRule;
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

it('caches sorted rules after first resolution', function (): void {
    $rules = (function (): \Generator {
        yield new RegistrySelectionRuleC;
        yield new RegistrySelectionRuleB;
        yield new RegistrySelectionRuleA;
    })();

    $registry = new AlertRuleRegistry($rules);

    $first = $registry->all();
    $second = $registry->all();

    expect($first)->toBe($second);
});

it('delegates alert evaluation through alert method rule callable', function (): void {
    $now = Carbon::parse('2026-02-20 00:00:00');
    $event = new AlertEvent(['alert_type' => 'queue_depth']);

    $service = \Mockery::mock(AlertEventService::class);
    $service->shouldReceive('evaluateQueueDepth')
        ->once()
        ->with($now)
        ->andReturn($event);

    $rule = new AlertMethodRule('queue_depth', 'evaluateQueueDepth', 80);

    expect($rule->key())->toBe('queue_depth')
        ->and($rule->priority())->toBe(80)
        ->and($rule->evaluate($service, $now))->toBe($event);
});
