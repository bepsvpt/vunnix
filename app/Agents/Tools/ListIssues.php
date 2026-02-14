<?php

namespace App\Agents\Tools;

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * AI SDK Tool: List GitLab issues for a project.
 *
 * Returns issues filtered by state, labels, and search query.
 * Wraps GitLabClient::listIssues() for use by the Conversation Engine.
 *
 * @see §14.2 — Tool catalog: ListIssues
 */
class ListIssues implements Tool
{
    public function __construct(
        protected GitLabClient $gitLab,
        protected ProjectAccessChecker $accessChecker,
    ) {}

    public function description(): string
    {
        return 'List issues in a GitLab project. Supports filtering by state (opened/closed/all), labels, and search query.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema
                ->integer()
                ->description('The GitLab project ID.')
                ->required(),
            'state' => $schema
                ->string()
                ->description('Filter by state: "opened", "closed", or "all" (defaults to "opened").'),
            'labels' => $schema
                ->string()
                ->description('Comma-separated list of label names to filter by (e.g. "bug,critical").'),
            'search' => $schema
                ->string()
                ->description('Search issues by title and description.'),
            'per_page' => $schema
                ->integer()
                ->description('Number of issues per page (defaults to 25, max 100).'),
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
            $issues = $this->gitLab->listIssues(
                projectId: $request->integer('project_id'),
                params: $params,
            );
        } catch (GitLabApiException $e) {
            return "Error listing issues: {$e->getMessage()}";
        }

        if (empty($issues)) {
            return 'No issues found matching the given filters.';
        }

        $lines = [];
        foreach ($issues as $issue) {
            $state = $issue['state'] ?? 'unknown';
            $iid = $issue['iid'] ?? '?';
            $title = $issue['title'] ?? 'Untitled';
            $labels = ! empty($issue['labels']) ? ' ['.implode(', ', $issue['labels']).']' : '';
            $assignee = isset($issue['assignee']['username']) ? " (@{$issue['assignee']['username']})" : '';

            $lines[] = "#{$iid} [{$state}] {$title}{$labels}{$assignee}";
        }

        $count = count($issues);

        return "Found {$count} issue(s):\n\n".implode("\n", $lines);
    }
}
