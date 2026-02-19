<?php

use App\Events\Webhook\IssueLabelChanged;
use App\Events\Webhook\MergeRequestMerged;
use App\Events\Webhook\MergeRequestOpened;
use App\Events\Webhook\MergeRequestUpdated;
use App\Events\Webhook\NoteOnIssue;
use App\Events\Webhook\PushToMRBranch;
use App\Modules\WebhookIntake\Application\Classifiers\IssueLabelClassifier;
use App\Modules\WebhookIntake\Application\Classifiers\IssueNoteClassifier;
use App\Modules\WebhookIntake\Application\Classifiers\MergeRequestLifecycleClassifier;
use App\Modules\WebhookIntake\Application\Classifiers\PushToMergeRequestClassifier;

it('returns null in PushToMergeRequestClassifier for unsupported event types', function (): void {
    $classifier = new PushToMergeRequestClassifier;
    $unsupported = new NoteOnIssue(1, 1001, [], 10, '@ai help', 55);
    $supported = new PushToMRBranch(1, 1001, [], 'refs/heads/feature/x', 'a', 'b', 55, [], 0);

    expect($classifier->priority())->toBe(60)
        ->and($classifier->supports($supported))->toBeTrue()
        ->and($classifier->classify($supported)?->intent)->toBe('incremental_review')
        ->and($classifier->supports($unsupported))->toBeFalse()
        ->and($classifier->classify($unsupported))->toBeNull();
});

it('returns null in IssueLabelClassifier when unsupported or label missing', function (): void {
    $classifier = new IssueLabelClassifier;
    $unsupported = new NoteOnIssue(1, 1001, [], 10, '@ai help', 55);
    $missingLabel = new IssueLabelChanged(1, 1001, [], 20, 'update', 55, ['bug']);
    $supported = new IssueLabelChanged(1, 1001, [], 20, 'update', 55, ['ai::develop']);

    expect($classifier->priority())->toBe(70)
        ->and($classifier->supports($supported))->toBeTrue()
        ->and($classifier->classify($supported)?->intent)->toBe('feature_dev')
        ->and($classifier->classify($missingLabel))->toBeNull()
        ->and($classifier->supports($unsupported))->toBeFalse()
        ->and($classifier->classify($unsupported))->toBeNull();
});

it('returns null in IssueNoteClassifier when unsupported or mention missing', function (): void {
    $classifier = new IssueNoteClassifier;
    $unsupported = new IssueLabelChanged(1, 1001, [], 20, 'update', 55, ['ai::develop']);
    $missingMention = new NoteOnIssue(1, 1001, [], 10, 'hello team', 55);
    $supported = new NoteOnIssue(1, 1001, [], 10, '@ai can you help?', 55);

    expect($classifier->priority())->toBe(80)
        ->and($classifier->supports($supported))->toBeTrue()
        ->and($classifier->classify($supported)?->intent)->toBe('issue_discussion')
        ->and($classifier->classify($missingMention))->toBeNull()
        ->and($classifier->supports($unsupported))->toBeFalse()
        ->and($classifier->classify($unsupported))->toBeNull();
});

it('returns null in MergeRequestLifecycleClassifier for unsupported events', function (): void {
    $classifier = new MergeRequestLifecycleClassifier;
    $opened = new MergeRequestOpened(1, 1001, [], 1, 'feature/a', 'main', 55, 'abc');
    $updated = new MergeRequestUpdated(1, 1001, [], 1, 'feature/a', 'main', 55, 'def');
    $merged = new MergeRequestMerged(1, 1001, [], 1, 'feature/a', 'main', 55, 'ghi');
    $unsupported = new NoteOnIssue(1, 1001, [], 10, '@ai help', 55);

    expect($classifier->priority())->toBe(100)
        ->and($classifier->supports($opened))->toBeTrue()
        ->and($classifier->classify($opened)?->intent)->toBe('auto_review')
        ->and($classifier->classify($updated)?->intent)->toBe('auto_review')
        ->and($classifier->classify($merged)?->intent)->toBe('acceptance_tracking')
        ->and($classifier->supports($unsupported))->toBeFalse()
        ->and($classifier->classify($unsupported))->toBeNull();
});
