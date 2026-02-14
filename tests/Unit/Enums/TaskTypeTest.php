<?php

use App\Enums\TaskType;

it('returns runner mode for code review', function () {
    expect(TaskType::CodeReview->executionMode())->toBe('runner');
});

it('returns runner mode for feature dev', function () {
    expect(TaskType::FeatureDev->executionMode())->toBe('runner');
});

it('returns runner mode for issue discussion', function () {
    expect(TaskType::IssueDiscussion->executionMode())->toBe('runner');
});

it('returns runner mode for UI adjustment', function () {
    expect(TaskType::UiAdjustment->executionMode())->toBe('runner');
});

it('returns runner mode for security audit', function () {
    expect(TaskType::SecurityAudit->executionMode())->toBe('runner');
});

it('returns server mode for PRD creation', function () {
    expect(TaskType::PrdCreation->executionMode())->toBe('server');
});
