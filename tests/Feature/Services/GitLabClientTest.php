<?php

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.gitlab.host' => 'https://gitlab.example.com',
        'services.gitlab.bot_token' => 'test-bot-pat',
    ]);
});

// ------------------------------------------------------------------
//  Auth & URL construction
// ------------------------------------------------------------------

it('sends PRIVATE-TOKEN header with bot PAT', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/issues*' => Http::response([], 200),
    ]);

    $client = app(GitLabClient::class);
    $client->listIssues(1);

    Http::assertSent(function ($request) {
        return $request->hasHeader('PRIVATE-TOKEN', 'test-bot-pat');
    });
});

it('constructs URLs using configured gitlab host', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/issues*' => Http::response([], 200),
    ]);

    $client = app(GitLabClient::class);
    $client->listIssues(42);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'gitlab.example.com/api/v4/projects/42/issues');
    });
});

it('defaults to gitlab.com when no host is configured', function (): void {
    config(['services.gitlab.host' => null]);

    Http::fake([
        'gitlab.com/api/v4/projects/1/issues*' => Http::response([], 200),
    ]);

    $client = app(GitLabClient::class);
    $client->listIssues(1);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'gitlab.com/api/v4/');
    });
});

it('sends Accept: application/json header', function (): void {
    Http::fake([
        'gitlab.example.com/*' => Http::response([], 200),
    ]);

    $client = app(GitLabClient::class);
    $client->listIssues(1);

    Http::assertSent(function ($request) {
        return str_contains($request->header('Accept')[0] ?? '', 'application/json');
    });
});

// ------------------------------------------------------------------
//  Files
// ------------------------------------------------------------------

it('reads a file from repository', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/repository/files/src%2Fapp.php*' => Http::response([
            'file_name' => 'app.php',
            'file_path' => 'src/app.php',
            'content' => base64_encode('<?php echo "hello";'),
            'encoding' => 'base64',
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->getFile(1, 'src/app.php', 'main');

    expect($result)
        ->toHaveKey('file_name', 'app.php')
        ->toHaveKey('file_path', 'src/app.php')
        ->toHaveKey('encoding', 'base64');
});

it('lists repository tree', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/repository/tree*' => Http::response([
            ['id' => 'abc', 'name' => 'src', 'type' => 'tree', 'path' => 'src', 'mode' => '040000'],
            ['id' => 'def', 'name' => 'README.md', 'type' => 'blob', 'path' => 'README.md', 'mode' => '100644'],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->listTree(1);

    expect($result)->toHaveCount(2)
        ->and($result[0]['type'])->toBe('tree')
        ->and($result[1]['type'])->toBe('blob');
});

// ------------------------------------------------------------------
//  Issues
// ------------------------------------------------------------------

it('lists issues with default pagination', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/issues*' => Http::response([
            ['iid' => 1, 'title' => 'Bug report'],
            ['iid' => 2, 'title' => 'Feature request'],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->listIssues(1);

    expect($result)->toHaveCount(2);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'per_page=25');
    });
});

it('lists issues with custom filters', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/issues*' => Http::response([], 200),
    ]);

    $client = app(GitLabClient::class);
    $client->listIssues(1, ['state' => 'opened', 'labels' => 'bug']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'state=opened')
            && str_contains($request->url(), 'labels=bug');
    });
});

it('gets a single issue', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/issues/5' => Http::response([
            'iid' => 5,
            'title' => 'Test issue',
            'description' => 'Issue body',
            'state' => 'opened',
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->getIssue(1, 5);

    expect($result)
        ->toHaveKey('iid', 5)
        ->toHaveKey('title', 'Test issue');
});

it('creates an issue', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/issues' => Http::response([
            'iid' => 10,
            'title' => 'New issue',
        ], 201),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->createIssue(1, [
        'title' => 'New issue',
        'description' => 'Description',
    ]);

    expect($result)->toHaveKey('iid', 10);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request['title'] === 'New issue';
    });
});

// ------------------------------------------------------------------
//  Merge Requests
// ------------------------------------------------------------------

it('lists merge requests', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests*' => Http::response([
            ['iid' => 1, 'title' => 'Fix bug'],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->listMergeRequests(1);

    expect($result)->toHaveCount(1)
        ->and($result[0]['title'])->toBe('Fix bug');
});

it('gets a single merge request', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests/3' => Http::response([
            'iid' => 3,
            'title' => 'Add feature',
            'state' => 'opened',
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->getMergeRequest(1, 3);

    expect($result)->toHaveKey('iid', 3);
});

it('gets merge request changes', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests/3/changes' => Http::response([
            'iid' => 3,
            'changes' => [
                ['old_path' => 'file.php', 'new_path' => 'file.php', 'diff' => '@@ -1 +1 @@'],
            ],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->getMergeRequestChanges(1, 3);

    expect($result)->toHaveKey('changes')
        ->and($result['changes'])->toHaveCount(1);
});

it('creates a merge request', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests' => Http::response([
            'iid' => 7,
            'title' => 'New MR',
        ], 201),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->createMergeRequest(1, [
        'source_branch' => 'feature',
        'target_branch' => 'main',
        'title' => 'New MR',
    ]);

    expect($result)->toHaveKey('iid', 7);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request['source_branch'] === 'feature';
    });
});

it('sends PUT request to update merge request', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/merge_requests/123' => Http::response([
            'iid' => 123,
            'title' => 'Updated title',
            'web_url' => 'https://gitlab.example.com/project/-/merge_requests/123',
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->updateMergeRequest(42, 123, [
        'title' => 'Updated title',
        'description' => 'Updated description',
    ]);

    expect($result['iid'])->toBe(123);
    expect($result['title'])->toBe('Updated title');

    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && str_contains($request->url(), 'merge_requests/123')
            && $request['title'] === 'Updated title';
    });
});

it('finds an open merge request for a branch', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests*' => Http::response([
            ['iid' => 42, 'source_branch' => 'feature/login', 'state' => 'opened'],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $mr = $client->findOpenMergeRequestForBranch(1, 'feature/login');

    expect($mr)->not->toBeNull()
        ->and($mr['iid'])->toBe(42);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'source_branch=feature')
            && str_contains($request->url(), 'state=opened')
            && str_contains($request->url(), 'per_page=1');
    });
});

it('returns null when no open merge request exists for branch', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests*' => Http::response([], 200),
    ]);

    $client = app(GitLabClient::class);
    $mr = $client->findOpenMergeRequestForBranch(1, 'feature/orphan');

    expect($mr)->toBeNull();
});

// ------------------------------------------------------------------
//  Comments (Notes)
// ------------------------------------------------------------------

it('creates a merge request note', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests/3/notes' => Http::response([
            'id' => 100,
            'body' => 'Review comment',
        ], 201),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->createMergeRequestNote(1, 3, 'Review comment');

    expect($result)->toHaveKey('id', 100)
        ->toHaveKey('body', 'Review comment');
});

it('updates a merge request note', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests/3/notes/100' => Http::response([
            'id' => 100,
            'body' => 'Updated comment',
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->updateMergeRequestNote(1, 3, 100, 'Updated comment');

    expect($result)->toHaveKey('body', 'Updated comment');

    Http::assertSent(function ($request) {
        return $request->method() === 'PUT';
    });
});

it('creates an issue note', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/issues/5/notes' => Http::response([
            'id' => 200,
            'body' => 'Issue comment',
        ], 201),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->createIssueNote(1, 5, 'Issue comment');

    expect($result)->toHaveKey('body', 'Issue comment');
});

it('lists merge request discussions', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests/5/discussions*' => Http::response([
            ['id' => 'disc-1', 'notes' => [['body' => 'Thread 1']]],
            ['id' => 'disc-2', 'notes' => [['body' => 'Thread 2']]],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $discussions = $client->listMergeRequestDiscussions(1, 5);

    expect($discussions)->toHaveCount(2)
        ->and($discussions[0]['id'])->toBe('disc-1')
        ->and($discussions[1]['id'])->toBe('disc-2');

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && str_contains($request->url(), 'discussions')
            && str_contains($request->url(), 'per_page=100');
    });
});

it('creates a merge request discussion thread', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests/3/discussions' => Http::response([
            'id' => 'abc123',
            'notes' => [['body' => 'Inline comment']],
        ], 201),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->createMergeRequestDiscussion(1, 3, 'Inline comment', [
        'base_sha' => 'aaa',
        'start_sha' => 'bbb',
        'head_sha' => 'ccc',
        'position_type' => 'text',
        'new_path' => 'file.php',
        'new_line' => 10,
    ]);

    expect($result)->toHaveKey('id', 'abc123');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && isset($request['position']);
    });
});

// ------------------------------------------------------------------
//  Branches
// ------------------------------------------------------------------

it('creates a branch', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/repository/branches' => Http::response([
            'name' => 'ai/feature-123',
            'commit' => ['id' => 'abc123'],
        ], 201),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->createBranch(1, 'ai/feature-123', 'main');

    expect($result)->toHaveKey('name', 'ai/feature-123');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request['branch'] === 'ai/feature-123'
            && $request['ref'] === 'main';
    });
});

it('compares two commits and returns diffs', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/repository/compare*' => Http::response([
            'diffs' => [
                ['new_path' => 'src/auth.py', 'diff' => '@@ -40,3 +40,5 @@...'],
            ],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->compareBranches(1, 'abc123', 'def456');

    expect($result)->toHaveKey('diffs');
    expect($result['diffs'])->toHaveCount(1);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/repository/compare')
            && str_contains($request->url(), 'from=abc123')
            && str_contains($request->url(), 'to=def456');
    });
});

// ------------------------------------------------------------------
//  Labels
// ------------------------------------------------------------------

it('sets merge request labels', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests/3' => Http::response([
            'iid' => 3,
            'labels' => ['ai::reviewed', 'ai::risk-low'],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->setMergeRequestLabels(1, 3, ['ai::reviewed', 'ai::risk-low']);

    expect($result['labels'])->toBe(['ai::reviewed', 'ai::risk-low']);

    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && $request['labels'] === 'ai::reviewed,ai::risk-low';
    });
});

it('adds merge request labels without removing existing ones', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests/3' => Http::response([
            'iid' => 3,
            'labels' => ['existing', 'ai::reviewed'],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->addMergeRequestLabels(1, 3, ['ai::reviewed']);

    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && $request['add_labels'] === 'ai::reviewed';
    });
});

it('removes specific labels from a merge request', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/merge_requests/3' => Http::response([
            'iid' => 3,
            'labels' => ['ai::reviewed'],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->removeMergeRequestLabels(1, 3, ['ai::risk-high', 'ai::risk-medium']);

    expect($result['labels'])->toBe(['ai::reviewed']);

    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && $request['remove_labels'] === 'ai::risk-high,ai::risk-medium';
    });
});

// ------------------------------------------------------------------
//  Commit Status
// ------------------------------------------------------------------

it('sets commit status', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/statuses/abc123*' => Http::response([
            'id' => 1,
            'sha' => 'abc123',
            'status' => 'success',
        ], 201),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->setCommitStatus(1, 'abc123', 'success', [
        'name' => 'vunnix/review',
        'description' => 'AI review passed',
    ]);

    expect($result)->toHaveKey('status', 'success');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request['state'] === 'success'
            && $request['name'] === 'vunnix/review';
    });
});

// ------------------------------------------------------------------
//  Webhooks
// ------------------------------------------------------------------

it('creates a webhook', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/hooks' => Http::response([
            'id' => 50,
            'url' => 'https://vunnix.example.com/webhook',
        ], 201),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->createWebhook(1, 'https://vunnix.example.com/webhook', 'secret123', [
        'merge_requests_events' => true,
        'note_events' => true,
        'issues_events' => true,
        'push_events' => true,
    ]);

    expect($result)->toHaveKey('id', 50);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request['url'] === 'https://vunnix.example.com/webhook'
            && $request['token'] === 'secret123'
            && $request['merge_requests_events'] === true;
    });
});

it('deletes a webhook', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/hooks/50' => Http::response('', 204),
    ]);

    $client = app(GitLabClient::class);
    $client->deleteWebhook(1, 50);

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), 'hooks/50');
    });
});

// ------------------------------------------------------------------
//  Pipelines
// ------------------------------------------------------------------

it('triggers a pipeline', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/trigger/pipeline' => Http::response([
            'id' => 999,
            'status' => 'pending',
        ], 201),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->triggerPipeline(1, 'main', 'trigger-token-123', [
        'TASK_ID' => '42',
    ]);

    expect($result)->toHaveKey('id', 999)
        ->toHaveKey('status', 'pending');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request['token'] === 'trigger-token-123'
            && $request['ref'] === 'main'
            && $request['variables[TASK_ID]'] === '42';
    });
});

// ------------------------------------------------------------------
//  Error handling
// ------------------------------------------------------------------

it('throws GitLabApiException on 404 response', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/issues/999' => Http::response([
            'message' => '404 Not Found',
        ], 404),
    ]);

    $client = app(GitLabClient::class);
    $client->getIssue(1, 999);
})->throws(GitLabApiException::class);

it('throws GitLabApiException on 500 response', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/issues*' => Http::response('Internal Server Error', 500),
    ]);

    $client = app(GitLabClient::class);
    $client->listIssues(1);
})->throws(GitLabApiException::class);

it('throws GitLabApiException on 429 rate limit response', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/issues*' => Http::response([
            'message' => '429 Too Many Requests',
        ], 429),
    ]);

    $client = app(GitLabClient::class);
    $client->listIssues(1);
})->throws(GitLabApiException::class);

it('logs warning on error responses', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/1/issues*' => Http::response('Server Error', 500),
    ]);

    \Illuminate\Support\Facades\Log::shouldReceive('warning')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'GitLab API error')
                && $context['status'] === 500;
        });

    $client = app(GitLabClient::class);

    try {
        $client->listIssues(1);
    } catch (GitLabApiException) {
        // Expected — we're testing the log call
    }
});
