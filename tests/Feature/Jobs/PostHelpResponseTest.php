<?php

use App\Exceptions\GitLabApiException;
use App\Jobs\PostHelpResponse;
use App\Services\GitLabClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// ─── Success path ────────────────────────────────────────────

it('posts help message to GitLab MR note on success', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response([
            'id' => 1234,
            'body' => 'mocked',
        ], 201),
    ]);

    $job = new PostHelpResponse(
        gitlabProjectId: 42,
        mergeRequestIid: 7,
        unrecognizedCommand: 'deploy',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request->url(), '/merge_requests/7/notes')
            && str_contains($request['body'], "I didn't recognize the command `deploy`")
            && str_contains($request['body'], '**Available commands:**');
    });
});

// ─── Exception path ──────────────────────────────────────────

it('logs warning and rethrows when GitLab API fails', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response('Server Error', 500),
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'GitLab API error');
        });

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Failed to post help response on MR'
                && $context['project_id'] === 42
                && $context['mr_iid'] === 7;
        });

    $job = new PostHelpResponse(
        gitlabProjectId: 42,
        mergeRequestIid: 7,
        unrecognizedCommand: 'deploy',
    );

    expect(fn () => $job->handle(app(GitLabClient::class)))
        ->toThrow(GitLabApiException::class);
});
