<?php

use App\Services\GitLabClient;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    config(['services.gitlab.host' => 'https://gitlab.example.com']);
    config(['services.gitlab.bot_token' => 'test-token']);
});

it('fetches project details via getProject', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42' => Http::response([
            'id' => 42,
            'name' => 'My Project',
            'visibility' => 'internal',
            'path_with_namespace' => 'group/my-project',
        ]),
    ]);

    $client = new GitLabClient();
    $result = $client->getProject(42);

    expect($result)
        ->toHaveKey('id', 42)
        ->toHaveKey('visibility', 'internal');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/api/v4/projects/42') &&
        $req->header('PRIVATE-TOKEN')[0] === 'test-token'
    );
});

it('fetches the authenticated user via getCurrentUser', function () {
    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response([
            'id' => 99,
            'username' => 'vunnix-bot',
        ]),
    ]);

    $client = new GitLabClient();
    $result = $client->getCurrentUser();

    expect($result)
        ->toHaveKey('id', 99)
        ->toHaveKey('username', 'vunnix-bot');
});

it('fetches project member via getProjectMember', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99,
            'username' => 'vunnix-bot',
            'access_level' => 40,
        ]),
    ]);

    $client = new GitLabClient();
    $result = $client->getProjectMember(42, 99);

    expect($result)
        ->toHaveKey('access_level', 40);
});

it('returns null for getProjectMember when user is not a member', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response(
            ['message' => '404 Not found'],
            404
        ),
    ]);

    $client = new GitLabClient();
    $result = $client->getProjectMember(42, 99);

    expect($result)->toBeNull();
});

it('creates a project label via createProjectLabel', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
            'name' => 'ai::reviewed',
            'color' => '#428BCA',
        ], 201),
    ]);

    $client = new GitLabClient();
    $result = $client->createProjectLabel(42, 'ai::reviewed', '#428BCA', 'Applied when AI review is complete');

    expect($result)
        ->toHaveKey('name', 'ai::reviewed');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/api/v4/projects/42/labels') &&
        $req->method() === 'POST' &&
        $req->data()['name'] === 'ai::reviewed' &&
        $req->data()['color'] === '#428BCA' &&
        $req->data()['description'] === 'Applied when AI review is complete'
    );
});

it('returns null for createProjectLabel when label already exists (409)', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response(
            ['message' => 'Label already exists'],
            409
        ),
    ]);

    $client = new GitLabClient();
    $result = $client->createProjectLabel(42, 'ai::reviewed', '#428BCA');

    expect($result)->toBeNull();
});

it('lists project labels via listProjectLabels', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/labels*' => Http::response([
            ['id' => 1, 'name' => 'bug', 'color' => '#d9534f'],
            ['id' => 2, 'name' => 'ai::reviewed', 'color' => '#428BCA'],
        ]),
    ]);

    $client = new GitLabClient();
    $result = $client->listProjectLabels(42);

    expect($result)->toHaveCount(2);
    expect($result[1]['name'])->toBe('ai::reviewed');
});
