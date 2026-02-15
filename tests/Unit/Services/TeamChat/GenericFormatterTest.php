<?php

use App\Services\TeamChat\GenericFormatter;

it('formats notification as plain text', function () {
    $formatter = new GenericFormatter();
    $payload = $formatter->format('task_completed', 'Review done');

    expect($payload)->toBe(['text' => 'Review done']);
});

it('appends action links as plain text URLs', function () {
    $formatter = new GenericFormatter();
    $payload = $formatter->format('task_completed', 'Done', [
        'links' => [
            ['label' => 'View MR', 'url' => 'https://example.com/mr/1'],
            ['label' => 'Dashboard', 'url' => 'https://vunnix.example.com'],
        ],
    ]);

    expect($payload['text'])->toContain('View MR: https://example.com/mr/1');
    expect($payload['text'])->toContain('Dashboard: https://vunnix.example.com');
});
