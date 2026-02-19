<?php

use App\Services\GitLabClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    config(['services.gitlab.host' => 'https://gitlab.example.com']);
    config(['services.gitlab.bot_token' => 'test-token']);
});

it('fetches project details via getProject', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42' => Http::response([
            'id' => 42,
            'name' => 'My Project',
            'visibility' => 'internal',
            'path_with_namespace' => 'group/my-project',
        ]),
    ]);

    $client = new GitLabClient;
    $result = $client->getProject(42);

    expect($result)
        ->toHaveKey('id', 42)
        ->toHaveKey('visibility', 'internal');

    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/api/v4/projects/42') &&
        $req->header('PRIVATE-TOKEN')[0] === 'test-token'
    );
});

it('fetches project by path via getProjectByPath', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/mygroup%2Fmyproject' => Http::response([
            'id' => 42,
            'name' => 'myproject',
            'path_with_namespace' => 'mygroup/myproject',
            'visibility' => 'internal',
        ]),
    ]);

    $client = new GitLabClient;
    $result = $client->getProjectByPath('mygroup/myproject');

    expect($result)
        ->toHaveKey('id', 42)
        ->toHaveKey('path_with_namespace', 'mygroup/myproject');

    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/api/v4/projects/mygroup%2Fmyproject')
    );
});

it('fetches the authenticated user via getCurrentUser', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response([
            'id' => 99,
            'username' => 'vunnix-bot',
        ]),
    ]);

    $client = new GitLabClient;
    $result = $client->getCurrentUser();

    expect($result)
        ->toHaveKey('id', 99)
        ->toHaveKey('username', 'vunnix-bot');
});

it('fetches project member via getProjectMember', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99,
            'username' => 'vunnix-bot',
            'access_level' => 40,
        ]),
    ]);

    $client = new GitLabClient;
    $result = $client->getProjectMember(42, 99);

    expect($result)
        ->toHaveKey('access_level', 40);
});

it('returns null for getProjectMember when user is not a member', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response(
            ['message' => '404 Not found'],
            404
        ),
    ]);

    $client = new GitLabClient;
    $result = $client->getProjectMember(42, 99);

    expect($result)->toBeNull();
});

it('creates a project label via createProjectLabel', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
            'name' => 'ai::reviewed',
            'color' => '#428BCA',
        ], 201),
    ]);

    $client = new GitLabClient;
    $result = $client->createProjectLabel(42, 'ai::reviewed', '#428BCA', 'Applied when AI review is complete');

    expect($result)
        ->toHaveKey('name', 'ai::reviewed');

    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/api/v4/projects/42/labels') &&
        $req->method() === 'POST' &&
        $req->data()['name'] === 'ai::reviewed' &&
        $req->data()['color'] === '#428BCA' &&
        $req->data()['description'] === 'Applied when AI review is complete'
    );
});

it('returns null for createProjectLabel when label already exists (409)', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response(
            ['message' => 'Label already exists'],
            409
        ),
    ]);

    $client = new GitLabClient;
    $result = $client->createProjectLabel(42, 'ai::reviewed', '#428BCA');

    expect($result)->toBeNull();
});

it('creates a pipeline trigger via createPipelineTrigger', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/triggers' => Http::response([
            'id' => 10,
            'token' => '6d056f63e50fe6f8c5f8f4aa10571c00',
            'description' => 'Vunnix task executor',
        ], 201),
    ]);

    $client = new GitLabClient;
    $result = $client->createPipelineTrigger(42, 'Vunnix task executor');

    expect($result)
        ->toHaveKey('id', 10)
        ->toHaveKey('token', '6d056f63e50fe6f8c5f8f4aa10571c00');

    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/api/v4/projects/42/triggers') &&
        $req->method() === 'POST' &&
        $req->data()['description'] === 'Vunnix task executor'
    );
});

it('lists project labels via listProjectLabels', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/labels*' => Http::response([
            ['id' => 1, 'name' => 'bug', 'color' => '#d9534f'],
            ['id' => 2, 'name' => 'ai::reviewed', 'color' => '#428BCA'],
        ]),
    ]);

    $client = new GitLabClient;
    $result = $client->listProjectLabels(42);

    expect($result)->toHaveCount(2);
    expect($result[1]['name'])->toBe('ai::reviewed');
});

it('searches code blobs with expected query parameters', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/search*' => Http::response([
            ['path' => 'app/Services/Foo.php', 'data' => 'match'],
        ], 200),
    ]);

    $client = new GitLabClient;
    $result = $client->searchCode(42, 'TODO');

    expect($result)->toHaveCount(1);

    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/api/v4/projects/42/search')
        && str_contains($req->url(), 'scope=blobs')
        && str_contains($req->url(), 'search=TODO')
        && str_contains($req->url(), 'per_page=20')
    );
});

it('lists project members using members/all endpoint', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/members/all*' => Http::response([
            ['id' => 1, 'username' => 'dev1'],
        ], 200),
    ]);

    $client = new GitLabClient;
    $result = $client->listProjectMembers(42, ['query' => 'dev']);

    expect($result)->toHaveCount(1)
        ->and($result[0]['username'])->toBe('dev1');
});

it('lists pipelines with default pagination', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/pipelines*' => Http::response([
            ['id' => 9001, 'status' => 'success'],
        ], 200),
    ]);

    $client = new GitLabClient;
    $result = $client->listPipelines(42);

    expect($result)->toHaveCount(1)
        ->and($result[0]['id'])->toBe(9001);

    Http::assertSent(fn ($req): bool => str_contains($req->url(), 'per_page=20'));
});

it('returns response from handleResponse fallback path when throw does not raise', function (): void {
    $client = new class extends GitLabClient
    {
        public function passthrough(\Illuminate\Http\Client\Response $response): \Illuminate\Http\Client\Response
        {
            return $this->handleResponse($response, 'fallback-test');
        }
    };

    $response = Mockery::mock(\Illuminate\Http\Client\Response::class);
    $response->shouldReceive('successful')->once()->andReturnFalse();
    $response->shouldReceive('status')->once()->andReturn(500);
    $response->shouldReceive('body')->once()->andReturn('error');
    $response->shouldReceive('throw')->once()->andReturnSelf();

    Log::shouldReceive('warning')->once();

    $result = $client->passthrough($response);

    expect($result)->toBe($response);
});
