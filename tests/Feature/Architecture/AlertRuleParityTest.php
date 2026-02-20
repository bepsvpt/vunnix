<?php

use App\Models\AlertEvent;
use App\Modules\Observability\Application\Registries\AlertRuleRegistry;
use App\Modules\Observability\Application\Rules\AlertMethodRule;
use App\Services\AlertEventService;
use App\Services\TeamChat\TeamChatNotificationService;

it('matches legacy and registry-driven evaluateAll outcomes for rule orchestration', function (): void {
    $teamChat = Mockery::mock(TeamChatNotificationService::class);

    $legacyService = Mockery::mock(AlertEventService::class, [$teamChat, null])->makePartial();
    $registryService = Mockery::mock(AlertEventService::class, [
        $teamChat,
        new AlertRuleRegistry([
            new AlertMethodRule('api_outage', 'evaluateApiOutage', 100),
            new AlertMethodRule('high_failure_rate', 'evaluateHighFailureRate', 90),
            new AlertMethodRule('queue_depth', 'evaluateQueueDepth', 80),
            new AlertMethodRule('auth_failure', 'evaluateAuthFailure', 70),
            new AlertMethodRule('disk_usage', 'evaluateDiskUsage', 60),
            new AlertMethodRule('container_health', 'evaluateContainerHealth', 50),
            new AlertMethodRule('cpu_usage', 'evaluateCpuUsage', 40),
            new AlertMethodRule('memory_usage', 'evaluateMemoryUsage', 30),
        ]),
    ])->makePartial();

    $alert = AlertEvent::factory()->make(['alert_type' => 'api_outage']);

    foreach ([$legacyService, $registryService] as $service) {
        $service->shouldReceive('evaluateApiOutage')->once()->andReturn($alert);
        $service->shouldReceive('evaluateHighFailureRate')->once()->andReturn(null);
        $service->shouldReceive('evaluateQueueDepth')->once()->andReturn(null);
        $service->shouldReceive('evaluateAuthFailure')->once()->andReturn(null);
        $service->shouldReceive('evaluateDiskUsage')->once()->andReturn(null);
        $service->shouldReceive('evaluateContainerHealth')->once()->andReturn(null);
        $service->shouldReceive('evaluateCpuUsage')->once()->andReturn(null);
        $service->shouldReceive('evaluateMemoryUsage')->once()->andReturn(null);
    }

    $legacy = $legacyService->evaluateAll(now());
    $registry = $registryService->evaluateAll(now());

    expect(count($legacy))->toBe(1)
        ->and(count($registry))->toBe(1)
        ->and($legacy[0]->alert_type)->toBe($registry[0]->alert_type);
});
