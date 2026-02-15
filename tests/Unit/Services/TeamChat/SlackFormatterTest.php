<?php

use App\Services\TeamChat\SlackFormatter;

it('formats basic notification with text fallback', function () {
    $formatter = new SlackFormatter();
    $payload = $formatter->format('task_completed', '🤖 Review complete on **project-x**');

    expect($payload)->toHaveKey('text', '🤖 Review complete on **project-x**');
    expect($payload)->toHaveKey('attachments');
    expect($payload['attachments'][0])->toHaveKey('color');
    expect($payload['attachments'][0])->toHaveKey('blocks');
});

it('uses correct color for high urgency', function () {
    $formatter = new SlackFormatter();
    $payload = $formatter->format('alert', 'API outage', ['urgency' => 'high']);

    expect($payload['attachments'][0]['color'])->toBe('#dc2626');
});

it('uses correct color for medium urgency', function () {
    $formatter = new SlackFormatter();
    $payload = $formatter->format('alert', 'Queue depth', ['urgency' => 'medium']);

    expect($payload['attachments'][0]['color'])->toBe('#f59e0b');
});

it('uses correct color for info urgency', function () {
    $formatter = new SlackFormatter();
    $payload = $formatter->format('task_completed', 'Done', ['urgency' => 'info']);

    expect($payload['attachments'][0]['color'])->toBe('#3b82f6');
});

it('includes action link buttons', function () {
    $formatter = new SlackFormatter();
    $payload = $formatter->format('task_completed', 'Review done', [
        'links' => [
            ['label' => 'View MR', 'url' => 'https://gitlab.example.com/mr/1'],
            ['label' => 'Dashboard', 'url' => 'https://vunnix.example.com'],
        ],
    ]);

    $blocks = $payload['attachments'][0]['blocks'];
    $actionsBlock = $blocks[1];
    expect($actionsBlock['type'])->toBe('actions');
    expect($actionsBlock['elements'])->toHaveCount(2);
    expect($actionsBlock['elements'][0]['url'])->toBe('https://gitlab.example.com/mr/1');
});
