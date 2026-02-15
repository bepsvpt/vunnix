<?php

use App\Services\EngineerFeedbackService;

// ─── classifyReactions ───────────────────────────────────────────

it('maps thumbsup to positive', function () {
    $service = new EngineerFeedbackService();

    $emoji = [
        ['name' => 'thumbsup', 'user' => ['id' => 5]],
    ];

    $result = $service->classifyReactions($emoji);

    expect($result)->toBe([
        'positive_count' => 1,
        'negative_count' => 0,
        'sentiment' => 'positive',
    ]);
});

it('maps thumbsdown to negative', function () {
    $service = new EngineerFeedbackService();

    $emoji = [
        ['name' => 'thumbsdown', 'user' => ['id' => 6]],
    ];

    $result = $service->classifyReactions($emoji);

    expect($result)->toBe([
        'positive_count' => 0,
        'negative_count' => 1,
        'sentiment' => 'negative',
    ]);
});

it('maps empty reactions to neutral', function () {
    $service = new EngineerFeedbackService();

    $result = $service->classifyReactions([]);

    expect($result)->toBe([
        'positive_count' => 0,
        'negative_count' => 0,
        'sentiment' => 'neutral',
    ]);
});

it('counts multiple reactions and determines sentiment by majority', function () {
    $service = new EngineerFeedbackService();

    $emoji = [
        ['name' => 'thumbsup', 'user' => ['id' => 1]],
        ['name' => 'thumbsup', 'user' => ['id' => 2]],
        ['name' => 'thumbsdown', 'user' => ['id' => 3]],
    ];

    $result = $service->classifyReactions($emoji);

    expect($result)->toBe([
        'positive_count' => 2,
        'negative_count' => 1,
        'sentiment' => 'positive',
    ]);
});

it('returns neutral sentiment when positive and negative counts are equal', function () {
    $service = new EngineerFeedbackService();

    $emoji = [
        ['name' => 'thumbsup', 'user' => ['id' => 1]],
        ['name' => 'thumbsdown', 'user' => ['id' => 2]],
    ];

    $result = $service->classifyReactions($emoji);

    expect($result)->toBe([
        'positive_count' => 1,
        'negative_count' => 1,
        'sentiment' => 'neutral',
    ]);
});

it('ignores non-thumbs emoji reactions', function () {
    $service = new EngineerFeedbackService();

    $emoji = [
        ['name' => 'thumbsup', 'user' => ['id' => 1]],
        ['name' => 'heart', 'user' => ['id' => 2]],
        ['name' => 'rocket', 'user' => ['id' => 3]],
        ['name' => 'thumbsdown', 'user' => ['id' => 4]],
    ];

    $result = $service->classifyReactions($emoji);

    expect($result)->toBe([
        'positive_count' => 1,
        'negative_count' => 1,
        'sentiment' => 'neutral',
    ]);
});

// ─── inferSentimentFromThreadState ───────────────────────────────

it('infers neutral sentiment from all thread states when no reactions', function () {
    $service = new EngineerFeedbackService();

    expect($service->inferSentimentFromThreadState('accepted'))->toBe('neutral');
    expect($service->inferSentimentFromThreadState('accepted_auto'))->toBe('neutral');
    expect($service->inferSentimentFromThreadState('dismissed'))->toBe('neutral');
});
