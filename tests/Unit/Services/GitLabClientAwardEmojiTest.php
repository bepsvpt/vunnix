<?php

use App\Services\GitLabClient;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('fetches award emoji for a merge request discussion note', function (): void {
    Http::fake([
        '*/api/v4/projects/42/merge_requests/10/discussions/disc-1/notes/100/award_emoji*' => Http::response([
            ['id' => 1, 'name' => 'thumbsup', 'user' => ['id' => 5, 'username' => 'engineer1']],
            ['id' => 2, 'name' => 'thumbsdown', 'user' => ['id' => 6, 'username' => 'engineer2']],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $emoji = $client->listNoteAwardEmoji(42, 10, 'disc-1', 100);

    expect($emoji)->toHaveCount(2);
    expect($emoji[0]['name'])->toBe('thumbsup');
    expect($emoji[1]['name'])->toBe('thumbsdown');
});

it('returns empty array when note has no award emoji', function (): void {
    Http::fake([
        '*/api/v4/projects/42/merge_requests/10/discussions/disc-1/notes/100/award_emoji*' => Http::response([], 200),
    ]);

    $client = app(GitLabClient::class);
    $emoji = $client->listNoteAwardEmoji(42, 10, 'disc-1', 100);

    expect($emoji)->toBeEmpty();
});
