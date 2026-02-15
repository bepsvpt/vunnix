<?php

namespace App\Services;

use App\Exceptions\GitLabApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reusable HTTP client for GitLab REST API v4.
 *
 * Authenticates as the Vunnix bot account using a Personal Access Token (PAT).
 * Separate from GitLabService (T8) which uses user OAuth tokens for membership sync.
 */
class GitLabClient
{
    protected string $baseUrl;

    protected string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.gitlab.host') ?: 'https://gitlab.com', '/');
        $this->token = config('services.gitlab.bot_token') ?: '';
    }

    // ------------------------------------------------------------------
    //  HTTP foundation
    // ------------------------------------------------------------------

    /**
     * Build a pre-configured HTTP client with bot PAT authentication.
     */
    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'PRIVATE-TOKEN' => $this->token,
        ])->acceptJson();
    }

    /**
     * Build the full API URL for a given path.
     */
    protected function url(string $path): string
    {
        return $this->baseUrl . '/api/v4/' . ltrim($path, '/');
    }

    /**
     * Handle a GitLab API response — log and throw classified exception on errors.
     *
     * @throws GitLabApiException
     */
    protected function handleResponse(Response $response, string $context): Response
    {
        if ($response->successful()) {
            return $response;
        }

        Log::warning("GitLab API error: {$context}", [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        try {
            $response->throw();
        } catch (RequestException $e) {
            throw GitLabApiException::fromRequestException($e, $context);
        }

        return $response; // unreachable, satisfies static analysis
    }

    // ------------------------------------------------------------------
    //  Files
    // ------------------------------------------------------------------

    /**
     * Read a file from a repository.
     *
     * @return array{file_name: string, file_path: string, content: string, encoding: string, ...}
     */
    public function getFile(int $projectId, string $filePath, string $ref = 'main'): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/repository/files/" . urlencode($filePath)),
            ['ref' => $ref],
        );

        return $this->handleResponse($response, "getFile {$filePath}")->json();
    }

    /**
     * List repository tree (files and directories).
     *
     * @return array<int, array{id: string, name: string, type: string, path: string, mode: string}>
     */
    public function listTree(int $projectId, string $path = '', string $ref = 'main', bool $recursive = false): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/repository/tree"),
            array_filter([
                'path' => $path,
                'ref' => $ref,
                'recursive' => $recursive ? 'true' : null,
                'per_page' => 100,
            ]),
        );

        return $this->handleResponse($response, "listTree {$path}")->json();
    }

    /**
     * Search code across a repository (project-scoped blob search).
     *
     * @return array<int, array{basename: string, data: string, path: string, filename: string, ref: string, startline: int, project_id: int}>
     */
    public function searchCode(int $projectId, string $query): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/search"),
            [
                'scope' => 'blobs',
                'search' => $query,
                'per_page' => 20,
            ],
        );

        return $this->handleResponse($response, "searchCode {$query}")->json();
    }

    // ------------------------------------------------------------------
    //  Issues
    // ------------------------------------------------------------------

    /**
     * List issues for a project.
     *
     * @param  array<string, mixed>  $params  Filters: state, labels, search, per_page, etc.
     * @return array<int, array>
     */
    public function listIssues(int $projectId, array $params = []): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/issues"),
            array_merge(['per_page' => 25], $params),
        );

        return $this->handleResponse($response, 'listIssues')->json();
    }

    /**
     * Get a single issue.
     */
    public function getIssue(int $projectId, int $issueIid): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/issues/{$issueIid}"),
        );

        return $this->handleResponse($response, "getIssue #{$issueIid}")->json();
    }

    /**
     * Create an issue.
     *
     * @param  array<string, mixed>  $data  title, description, labels, assignee_ids, etc.
     */
    public function createIssue(int $projectId, array $data): array
    {
        $response = $this->request()->post(
            $this->url("projects/{$projectId}/issues"),
            $data,
        );

        return $this->handleResponse($response, 'createIssue')->json();
    }

    // ------------------------------------------------------------------
    //  Merge Requests
    // ------------------------------------------------------------------

    /**
     * List merge requests for a project.
     *
     * @param  array<string, mixed>  $params  Filters: state, labels, search, per_page, etc.
     * @return array<int, array>
     */
    public function listMergeRequests(int $projectId, array $params = []): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/merge_requests"),
            array_merge(['per_page' => 25], $params),
        );

        return $this->handleResponse($response, 'listMergeRequests')->json();
    }

    /**
     * Get a single merge request.
     */
    public function getMergeRequest(int $projectId, int $mrIid): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/merge_requests/{$mrIid}"),
        );

        return $this->handleResponse($response, "getMergeRequest !{$mrIid}")->json();
    }

    /**
     * Get merge request changes (diff).
     */
    public function getMergeRequestChanges(int $projectId, int $mrIid): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/merge_requests/{$mrIid}/changes"),
        );

        return $this->handleResponse($response, "getMergeRequestChanges !{$mrIid}")->json();
    }

    /**
     * Create a merge request.
     *
     * @param  array<string, mixed>  $data  source_branch, target_branch, title, description, etc.
     */
    public function createMergeRequest(int $projectId, array $data): array
    {
        $response = $this->request()->post(
            $this->url("projects/{$projectId}/merge_requests"),
            $data,
        );

        return $this->handleResponse($response, 'createMergeRequest')->json();
    }

    /**
     * Update an existing merge request.
     *
     * Used by T72 designer iteration flow to update MR title/description
     * when pushing corrections to the same branch.
     *
     * @param  array<string, mixed>  $data  title, description, etc.
     */
    public function updateMergeRequest(int $projectId, int $mrIid, array $data): array
    {
        $response = $this->request()->put(
            $this->url("projects/{$projectId}/merge_requests/{$mrIid}"),
            $data,
        );

        return $this->handleResponse($response, "updateMergeRequest !{$mrIid}")->json();
    }

    /**
     * Find an open merge request for a given source branch.
     *
     * Used by incremental review (T40) to resolve push events to their
     * associated MR. Returns null if no open MR exists for the branch.
     */
    public function findOpenMergeRequestForBranch(int $projectId, string $sourceBranch): ?array
    {
        $mrs = $this->listMergeRequests($projectId, [
            'source_branch' => $sourceBranch,
            'state' => 'opened',
            'per_page' => 1,
        ]);

        return $mrs[0] ?? null;
    }

    // ------------------------------------------------------------------
    //  Comments (Notes)
    // ------------------------------------------------------------------

    /**
     * Post a comment on a merge request.
     */
    public function createMergeRequestNote(int $projectId, int $mrIid, string $body): array
    {
        $response = $this->request()->post(
            $this->url("projects/{$projectId}/merge_requests/{$mrIid}/notes"),
            ['body' => $body],
        );

        return $this->handleResponse($response, "createMRNote !{$mrIid}")->json();
    }

    /**
     * Edit an existing comment on a merge request.
     */
    public function updateMergeRequestNote(int $projectId, int $mrIid, int $noteId, string $body): array
    {
        $response = $this->request()->put(
            $this->url("projects/{$projectId}/merge_requests/{$mrIid}/notes/{$noteId}"),
            ['body' => $body],
        );

        return $this->handleResponse($response, "updateMRNote !{$mrIid}#{$noteId}")->json();
    }

    /**
     * Post a comment on an issue.
     */
    public function createIssueNote(int $projectId, int $issueIid, string $body): array
    {
        $response = $this->request()->post(
            $this->url("projects/{$projectId}/issues/{$issueIid}/notes"),
            ['body' => $body],
        );

        return $this->handleResponse($response, "createIssueNote #{$issueIid}")->json();
    }

    /**
     * List all discussion threads on a merge request.
     *
     * Returns all discussions (both inline diff threads and general MR-level).
     * Used by incremental review (T40) to check for existing threads before
     * posting duplicates (D33).
     *
     * @return array<int, array>
     */
    public function listMergeRequestDiscussions(int $projectId, int $mrIid, array $params = []): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/merge_requests/{$mrIid}/discussions"),
            array_merge(['per_page' => 100], $params),
        );

        return $this->handleResponse($response, "listMRDiscussions !{$mrIid}")->json();
    }

    /**
     * Create a merge request discussion thread (for inline code comments).
     *
     * @param  array<string, mixed>  $position  Position data: base_sha, start_sha, head_sha, old_path, new_path, new_line, etc.
     */
    public function createMergeRequestDiscussion(int $projectId, int $mrIid, string $body, array $position = []): array
    {
        $data = ['body' => $body];

        if (! empty($position)) {
            $data['position'] = $position;
        }

        $response = $this->request()->post(
            $this->url("projects/{$projectId}/merge_requests/{$mrIid}/discussions"),
            $data,
        );

        return $this->handleResponse($response, "createMRDiscussion !{$mrIid}")->json();
    }

    /**
     * List award emoji on a merge request discussion note.
     *
     * Used by T87 engineer feedback to read 👍/👎 reactions on AI review comments.
     *
     * @see https://docs.gitlab.com/ee/api/award_emoji.html#list-an-awardables-award-emoji
     *
     * @return array<int, array{id: int, name: string, user: array}>
     */
    public function listNoteAwardEmoji(int $projectId, int $mrIid, string $discussionId, int $noteId): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/merge_requests/{$mrIid}/discussions/{$discussionId}/notes/{$noteId}/award_emoji"),
            ['per_page' => 100],
        );

        return $this->handleResponse($response, "listNoteAwardEmoji !{$mrIid} disc:{$discussionId} note:{$noteId}")->json();
    }

    // ------------------------------------------------------------------
    //  Branches
    // ------------------------------------------------------------------

    /**
     * Create a branch.
     */
    public function createBranch(int $projectId, string $branchName, string $ref): array
    {
        $response = $this->request()->post(
            $this->url("projects/{$projectId}/repository/branches"),
            [
                'branch' => $branchName,
                'ref' => $ref,
            ],
        );

        return $this->handleResponse($response, "createBranch {$branchName}")->json();
    }

    /**
     * Compare two commits/branches to get diffs.
     *
     * Used by acceptance tracking (T86) to correlate push event changes
     * with AI finding locations for code change correlation (§16.2).
     *
     * @return array{diffs: array<int, array{new_path: string, diff: string, ...}>, ...}
     */
    public function compareBranches(int $projectId, string $from, string $to): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/repository/compare"),
            ['from' => $from, 'to' => $to],
        );

        return $this->handleResponse($response, "compareBranches {$from}..{$to}")->json();
    }

    // ------------------------------------------------------------------
    //  Labels
    // ------------------------------------------------------------------

    /**
     * Apply labels to a merge request (overwrites existing labels).
     *
     * @param  array<int, string>  $labels
     */
    public function setMergeRequestLabels(int $projectId, int $mrIid, array $labels): array
    {
        $response = $this->request()->put(
            $this->url("projects/{$projectId}/merge_requests/{$mrIid}"),
            ['labels' => implode(',', $labels)],
        );

        return $this->handleResponse($response, "setMRLabels !{$mrIid}")->json();
    }

    /**
     * Add labels to a merge request (preserves existing labels).
     *
     * @param  array<int, string>  $labels
     */
    public function addMergeRequestLabels(int $projectId, int $mrIid, array $labels): array
    {
        $response = $this->request()->put(
            $this->url("projects/{$projectId}/merge_requests/{$mrIid}"),
            ['add_labels' => implode(',', $labels)],
        );

        return $this->handleResponse($response, "addMRLabels !{$mrIid}")->json();
    }

    /**
     * Remove specific labels from a merge request.
     *
     * Used by incremental review (T40, D56) to clear stale AI risk labels
     * before applying updated ones.
     *
     * @param  array<int, string>  $labels
     */
    public function removeMergeRequestLabels(int $projectId, int $mrIid, array $labels): array
    {
        $response = $this->request()->put(
            $this->url("projects/{$projectId}/merge_requests/{$mrIid}"),
            ['remove_labels' => implode(',', $labels)],
        );

        return $this->handleResponse($response, "removeMRLabels !{$mrIid}")->json();
    }

    // ------------------------------------------------------------------
    //  Commit Status
    // ------------------------------------------------------------------

    /**
     * Set commit status (used for CI/CD integration).
     *
     * @param  string  $state  pending, running, success, failed, canceled
     * @param  array<string, mixed>  $options  name, target_url, description, coverage, etc.
     */
    public function setCommitStatus(int $projectId, string $sha, string $state, array $options = []): array
    {
        $response = $this->request()->post(
            $this->url("projects/{$projectId}/statuses/{$sha}"),
            array_merge(['state' => $state], $options),
        );

        return $this->handleResponse($response, "setCommitStatus {$sha}")->json();
    }

    // ------------------------------------------------------------------
    //  Webhooks
    // ------------------------------------------------------------------

    /**
     * Create a project webhook.
     *
     * @param  array<string, mixed>  $events  merge_requests_events, note_events, issues_events, push_events, etc.
     */
    public function createWebhook(int $projectId, string $url, string $secretToken, array $events = []): array
    {
        $response = $this->request()->post(
            $this->url("projects/{$projectId}/hooks"),
            array_merge([
                'url' => $url,
                'token' => $secretToken,
            ], $events),
        );

        return $this->handleResponse($response, 'createWebhook')->json();
    }

    /**
     * Delete a project webhook.
     */
    public function deleteWebhook(int $projectId, int $hookId): void
    {
        $response = $this->request()->delete(
            $this->url("projects/{$projectId}/hooks/{$hookId}"),
        );

        $this->handleResponse($response, "deleteWebhook #{$hookId}");
    }

    // ------------------------------------------------------------------
    //  Project Metadata
    // ------------------------------------------------------------------

    /**
     * Get the authenticated user (bot account).
     */
    public function getCurrentUser(): array
    {
        $response = $this->request()->get(
            $this->url('user'),
        );

        return $this->handleResponse($response, 'getCurrentUser')->json();
    }

    /**
     * Get project details (visibility, name, namespace, etc.).
     */
    public function getProject(int $projectId): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}"),
        );

        return $this->handleResponse($response, "getProject #{$projectId}")->json();
    }

    /**
     * Get project details by path (e.g. "bepsvpt-gl/vunnix").
     *
     * GitLab accepts URL-encoded paths in place of numeric project IDs.
     */
    public function getProjectByPath(string $pathWithNamespace): array
    {
        $encoded = urlencode($pathWithNamespace);

        $response = $this->request()->get(
            $this->url("projects/{$encoded}"),
        );

        return $this->handleResponse($response, "getProjectByPath {$pathWithNamespace}")->json();
    }

    /**
     * Get a specific member of a project (including inherited members).
     *
     * Uses the /members/all endpoint which includes inherited members
     * from parent groups. Returns null if the user is not a member.
     */
    public function getProjectMember(int $projectId, int $userId): ?array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/members/all/{$userId}"),
        );

        if ($response->status() === 404) {
            return null;
        }

        return $this->handleResponse($response, "getProjectMember #{$projectId}/#{$userId}")->json();
    }

    // ------------------------------------------------------------------
    //  Project Labels
    // ------------------------------------------------------------------

    /**
     * Create a project-level label.
     *
     * Returns null if the label already exists (409 Conflict) — idempotent.
     */
    public function createProjectLabel(int $projectId, string $name, string $color, string $description = ''): ?array
    {
        $response = $this->request()->post(
            $this->url("projects/{$projectId}/labels"),
            array_filter([
                'name' => $name,
                'color' => $color,
                'description' => $description,
            ]),
        );

        if ($response->status() === 409) {
            return null;
        }

        return $this->handleResponse($response, "createProjectLabel {$name}")->json();
    }

    /**
     * List all labels for a project.
     *
     * @return array<int, array{id: int, name: string, color: string, description: string|null}>
     */
    public function listProjectLabels(int $projectId): array
    {
        $response = $this->request()->get(
            $this->url("projects/{$projectId}/labels"),
            ['per_page' => 100],
        );

        return $this->handleResponse($response, "listProjectLabels #{$projectId}")->json();
    }

    // ------------------------------------------------------------------
    //  Pipelines
    // ------------------------------------------------------------------

    /**
     * Create a pipeline trigger token for a project.
     *
     * @return array{id: int, token: string, description: string}
     */
    public function createPipelineTrigger(int $projectId, string $description): array
    {
        $response = $this->request()->post(
            $this->url("projects/{$projectId}/triggers"),
            ['description' => $description],
        );

        return $this->handleResponse($response, 'createPipelineTrigger')->json();
    }

    /**
     * Trigger a pipeline via the pipeline triggers API.
     *
     * @param  array<string, string>  $variables  Key-value pairs for CI variables.
     */
    public function triggerPipeline(int $projectId, string $ref, string $triggerToken, array $variables = []): array
    {
        $data = [
            'token' => $triggerToken,
            'ref' => $ref,
        ];

        foreach ($variables as $key => $value) {
            $data["variables[{$key}]"] = $value;
        }

        $response = $this->request()->post(
            $this->url("projects/{$projectId}/trigger/pipeline"),
            $data,
        );

        return $this->handleResponse($response, "triggerPipeline ref={$ref}")->json();
    }
}
