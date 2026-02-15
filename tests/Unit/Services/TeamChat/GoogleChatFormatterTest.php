<?php

use App\Services\TeamChat\GoogleChatFormatter;

it('formats notification with text and cardsV2', function () {
    $formatter = new GoogleChatFormatter();
    $payload = $formatter->format('task_completed', 'Review done');

    expect($payload)->toHaveKey('text', 'Review done');
    expect($payload)->toHaveKey('cardsV2');
    expect($payload['cardsV2'][0]['card']['header']['title'])->toBe('Vunnix Notification');
});

it('uses Alert header for alert type', function () {
    $formatter = new GoogleChatFormatter();
    $payload = $formatter->format('alert', 'API outage detected');

    expect($payload['cardsV2'][0]['card']['header']['title'])->toBe('Vunnix Alert');
});

it('uses Task Failed header for task_failed type', function () {
    $formatter = new GoogleChatFormatter();
    $payload = $formatter->format('task_failed', 'Task failed');

    expect($payload['cardsV2'][0]['card']['header']['title'])->toBe('Task Failed');
});

it('includes action link buttons', function () {
    $formatter = new GoogleChatFormatter();
    $payload = $formatter->format('task_completed', 'Done', [
        'links' => [['label' => 'View MR', 'url' => 'https://example.com/mr/1']],
    ]);

    $widgets = $payload['cardsV2'][0]['card']['sections'][0]['widgets'];
    expect($widgets)->toHaveCount(2);
    $buttons = $widgets[1]['buttonList']['buttons'];
    expect($buttons[0]['text'])->toBe('View MR');
    expect($buttons[0]['onClick']['openLink']['url'])->toBe('https://example.com/mr/1');
});
