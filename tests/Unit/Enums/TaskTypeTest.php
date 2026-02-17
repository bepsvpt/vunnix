<?php

use App\Enums\TaskType;

it('returns runner mode for code review', function (): void {
    expect(TaskType::CodeReview->executionMode())->toBe('runner');
});

it('returns runner mode for feature dev', function (): void {
    expect(TaskType::FeatureDev->executionMode())->toBe('runner');
});

it('returns runner mode for issue discussion', function (): void {
    expect(TaskType::IssueDiscussion->executionMode())->toBe('runner');
});

it('returns runner mode for UI adjustment', function (): void {
    expect(TaskType::UiAdjustment->executionMode())->toBe('runner');
});

it('returns runner mode for security audit', function (): void {
    expect(TaskType::SecurityAudit->executionMode())->toBe('runner');
});

it('returns server mode for PRD creation', function (): void {
    expect(TaskType::PrdCreation->executionMode())->toBe('server');
});
