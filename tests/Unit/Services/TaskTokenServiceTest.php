<?php

use App\Services\TaskTokenService;
use Carbon\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::create(2026, 2, 14, 12, 0, 0));
    $this->appKey = str_repeat('a', 32);
    $this->budgetMinutes = 60;
    $this->service = new TaskTokenService($this->appKey, $this->budgetMinutes);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('generates a non-empty token string', function (): void {
    $token = $this->service->generate(taskId: 42);

    expect($token)->toBeString()->not->toBeEmpty();
});

it('generates different tokens for different task IDs', function (): void {
    $token1 = $this->service->generate(taskId: 1);
    $token2 = $this->service->generate(taskId: 2);

    expect($token1)->not->toBe($token2);
});

it('validates a freshly generated token for the correct task ID', function (): void {
    $token = $this->service->generate(taskId: 42);

    expect($this->service->validate($token, taskId: 42))->toBeTrue();
});

it('rejects a token for the wrong task ID', function (): void {
    $token = $this->service->generate(taskId: 42);

    expect($this->service->validate($token, taskId: 99))->toBeFalse();
});

it('rejects an expired token', function (): void {
    $token = $this->service->generate(taskId: 42);

    // Travel past the 60-minute budget
    Carbon::setTestNow(Carbon::create(2026, 2, 14, 13, 1, 0));

    expect($this->service->validate($token, taskId: 42))->toBeFalse();
});

it('accepts a token within the budget window', function (): void {
    $token = $this->service->generate(taskId: 42);

    // 30 minutes later — still valid
    Carbon::setTestNow(Carbon::create(2026, 2, 14, 12, 30, 0));

    expect($this->service->validate($token, taskId: 42))->toBeTrue();
});

it('rejects a token at exactly the budget boundary', function (): void {
    $token = $this->service->generate(taskId: 42);

    // Exactly 60 minutes later — token should be expired (>=, not >)
    Carbon::setTestNow(Carbon::create(2026, 2, 14, 13, 0, 1));

    expect($this->service->validate($token, taskId: 42))->toBeFalse();
});

it('rejects a tampered token', function (): void {
    $token = $this->service->generate(taskId: 42);
    $tampered = $token.'x';

    expect($this->service->validate($tampered, taskId: 42))->toBeFalse();
});

it('rejects completely invalid token strings', function (): void {
    expect($this->service->validate('not-a-valid-token', taskId: 42))->toBeFalse();
    expect($this->service->validate('', taskId: 42))->toBeFalse();
});

it('uses configurable task budget minutes', function (): void {
    $service = new TaskTokenService($this->appKey, budgetMinutes: 10);

    $token = $service->generate(taskId: 42);

    // 9 minutes later — still valid
    Carbon::setTestNow(Carbon::create(2026, 2, 14, 12, 9, 0));
    expect($service->validate($token, taskId: 42))->toBeTrue();

    // 11 minutes later — expired
    Carbon::setTestNow(Carbon::create(2026, 2, 14, 12, 11, 0));
    expect($service->validate($token, taskId: 42))->toBeFalse();
});

it('rejects a token generated with a different app key', function (): void {
    $token = $this->service->generate(taskId: 42);

    $otherService = new TaskTokenService(str_repeat('b', 32), $this->budgetMinutes);

    expect($otherService->validate($token, taskId: 42))->toBeFalse();
});

it('generates a token that is URL-safe base64 encoded', function (): void {
    $token = $this->service->generate(taskId: 42);

    // Token should not contain characters problematic in URLs/headers
    expect($token)->not->toContain("\n");
    expect($token)->toMatch('/^[A-Za-z0-9_\-=.:]+$/');
});
