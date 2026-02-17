<?php

namespace App\Agents\Tools;

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * AI SDK Tool: List GitLab merge requests for a project.
 *
 * Returns merge requests filtered by state, labels, and search query.
 * Wraps GitLabClient::listMergeRequests() for use by the Conversation Engine.
 *
 * @see §14.2 — Tool catalog: ListMergeRequests
 */
class ListMergeRequests implements Tool
{
    public function __construct(
        protected GitLabClient $gitLab,
        protected ProjectAccessChecker $accessChecker,
    ) {}

    public function description(): string
    {
        return 'List merge requests in a GitLab project. Supports filtering by state (opened/closed/merged/all), labels, and search query.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema
                ->integer()
                ->description('The GitLab project ID.')
                ->required(),
            'state' => $schema
                ->string()
                ->description('Filter by state: "opened", "closed", "merged", or "all" (defaults to "opened").'),
            'labels' => $schema
                ->string()
                ->description('Comma-separated list of label names to filter by (e.g. "bug,critical").'),
            'search' => $schema
                ->string()
                ->description('Search merge requests by title and description.'),
            'per_page' => $schema
                ->integer()
                ->description('Number of merge requests per page (defaults to 25, max 100).'),
        ];
    }

    public function handle(Request $request): string
    {
        $rejection = $this->accessChecker->check($request->integer('project_id'));

        if ($rejection !== null) {
            return $rejection;
        }

        $params = [];

        $state = (string) $request->string('state', '');
        if ($state !== '') {
            $params['state'] = $state;
        }

        $labels = (string) $request->string('labels', '');
        if ($labels !== '') {
            $params['labels'] = $labels;
        }

        $search = (string) $request->string('search', '');
        if ($search !== '') {
            $params['search'] = $search;
        }

        $perPage = $request->integer('per_page', 0);
        if ($perPage > 0) {
            $params['per_page'] = $perPage;
        }

        try {
            $mergeRequests = $this->gitLab->listMergeRequests(
                projectId: $request->integer('project_id'),
                params: $params,
            );
        } catch (GitLabApiException $e) {
            return "Error listing merge requests: {$e->getMessage()}";
        }

        if ($mergeRequests === []) {
            return 'No merge requests found matching the given filters.';
        }

        $lines = [];
        foreach ($mergeRequests as $mr) {
            $state = $mr['state'] ?? 'unknown';
            $iid = $mr['iid'] ?? '?';
            $title = $mr['title'] ?? 'Untitled';
            $sourceBranch = $mr['source_branch'] ?? '?';
            $targetBranch = $mr['target_branch'] ?? '?';
            $labels = ($mr['labels'] ?? []) === [] ? '' : ' ['.implode(', ', $mr['labels']).']';
            $author = isset($mr['author']['username']) ? " (@{$mr['author']['username']})" : '';

            $lines[] = "!{$iid} [{$state}] {$title} ({$sourceBranch} → {$targetBranch}){$labels}{$author}";
        }

        $count = count($mergeRequests);

        return "Found {$count} merge request(s):\n\n".implode("\n", $lines);
    }
}
