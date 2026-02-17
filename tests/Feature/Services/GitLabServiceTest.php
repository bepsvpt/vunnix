<?php

use App\Services\GitLabService;
use Illuminate\Support\Facades\Http;

it('fetches user projects from gitlab api', function (): void {
    Http::fake([
        '*/api/v4/projects?membership=true&per_page=100&page=1' => Http::response([
            [
                'id' => 101,
                'name' => 'My Project',
                'path_with_namespace' => 'group/my-project',
                'description' => 'A test project',
                'permissions' => [
                    'project_access' => ['access_level' => 30],
                    'group_access' => null,
                ],
            ],
            [
                'id' => 202,
                'name' => 'Another Project',
                'path_with_namespace' => 'group/another-project',
                'description' => null,
                'permissions' => [
                    'project_access' => null,
                    'group_access' => ['access_level' => 40],
                ],
            ],
        ], 200),
    ]);

    $service = app(GitLabService::class);
    $projects = $service->getUserProjects('fake-token');

    expect($projects)->toHaveCount(2)
        ->and($projects[0]['id'])->toBe(101)
        ->and($projects[0]['name'])->toBe('My Project')
        ->and($projects[1]['id'])->toBe(202);
});

it('resolves access level from project_access or group_access', function (): void {
    Http::fake([
        '*/api/v4/projects?membership=true&per_page=100&page=1' => Http::response([
            [
                'id' => 101,
                'name' => 'Direct Access',
                'path_with_namespace' => 'group/direct',
                'description' => null,
                'permissions' => [
                    'project_access' => ['access_level' => 30],
                    'group_access' => ['access_level' => 20],
                ],
            ],
            [
                'id' => 202,
                'name' => 'Group Only',
                'path_with_namespace' => 'group/group-only',
                'description' => null,
                'permissions' => [
                    'project_access' => null,
                    'group_access' => ['access_level' => 40],
                ],
            ],
        ], 200),
    ]);

    $service = app(GitLabService::class);
    $projects = $service->getUserProjects('fake-token');

    // Should take the higher of project_access and group_access
    expect($service->resolveAccessLevel($projects[0]))->toBe(30)
        ->and($service->resolveAccessLevel($projects[1]))->toBe(40);
});

it('paginates through all gitlab project pages', function (): void {
    Http::fake([
        '*/api/v4/projects?membership=true&per_page=100&page=1' => Http::response(
            array_fill(0, 100, [
                'id' => 1,
                'name' => 'Project',
                'path_with_namespace' => 'g/p',
                'description' => null,
                'permissions' => ['project_access' => ['access_level' => 30], 'group_access' => null],
            ]),
            200,
            ['x-next-page' => '2'],
        ),
        '*/api/v4/projects?membership=true&per_page=100&page=2' => Http::response([
            [
                'id' => 2,
                'name' => 'Last Project',
                'path_with_namespace' => 'g/last',
                'description' => null,
                'permissions' => ['project_access' => ['access_level' => 30], 'group_access' => null],
            ],
        ], 200),
    ]);

    $service = app(GitLabService::class);
    $projects = $service->getUserProjects('fake-token');

    expect($projects)->toHaveCount(101);
});

it('returns empty array when gitlab api returns error', function (): void {
    Http::fake([
        '*/api/v4/projects*' => Http::response('Unauthorized', 401),
    ]);

    $service = app(GitLabService::class);
    $projects = $service->getUserProjects('fake-token');

    expect($projects)->toBeArray()->toBeEmpty();
});

it('uses the configured gitlab url as base', function (): void {
    config(['services.gitlab.host' => 'https://my-gitlab.example.com']);

    Http::fake([
        'my-gitlab.example.com/api/v4/projects?membership=true&per_page=100&page=1' => Http::response([], 200),
    ]);

    $service = app(GitLabService::class);
    $service->getUserProjects('fake-token');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'my-gitlab.example.com/api/v4/projects');
    });
});
