<?php

use App\Services\TeamChat\MattermostFormatter;

it('formats notification with text and attachments', function () {
    $formatter = new MattermostFormatter();
    $payload = $formatter->format('task_completed', 'Review complete');

    expect($payload)->toHaveKey('text', 'Review complete');
    expect($payload)->toHaveKey('attachments');
    expect($payload['attachments'][0])->toHaveKey('color');
    expect($payload['attachments'][0])->toHaveKey('text');
});

it('uses correct color for urgency levels', function () {
    $formatter = new MattermostFormatter();

    $high = $formatter->format('alert', 'Error', ['urgency' => 'high']);
    expect($high['attachments'][0]['color'])->toBe('#dc2626');

    $medium = $formatter->format('alert', 'Warning', ['urgency' => 'medium']);
    expect($medium['attachments'][0]['color'])->toBe('#f59e0b');
});

it('includes project and urgency fields', function () {
    $formatter = new MattermostFormatter();
    $payload = $formatter->format('alert', 'Queue depth growing', [
        'urgency' => 'medium',
        'project' => 'my-app',
    ]);

    $fields = $payload['attachments'][0]['fields'];
    expect($fields)->toHaveCount(2);
    expect($fields[0]['title'])->toBe('Project');
    expect($fields[0]['value'])->toBe('my-app');
});

it('appends action links as markdown', function () {
    $formatter = new MattermostFormatter();
    $payload = $formatter->format('task_completed', 'Done', [
        'links' => [['label' => 'View MR', 'url' => 'https://example.com/mr/1']],
    ]);

    expect($payload['attachments'][0]['text'])->toContain('[View MR](https://example.com/mr/1)');
});
