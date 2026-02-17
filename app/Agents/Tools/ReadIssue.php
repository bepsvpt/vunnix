<?php

namespace App\Agents\Tools;

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * AI SDK Tool: Read a specific GitLab issue.
 *
 * Returns the full details and description of a single issue.
 * Wraps GitLabClient::getIssue() for use by the Conversation Engine.
 *
 * @see §14.2 — Tool catalog: ReadIssue
 */
class ReadIssue implements Tool
{
    public function __construct(
        protected GitLabClient $gitLab,
        protected ProjectAccessChecker $accessChecker,
    ) {}

    public function description(): string
    {
        return 'Read a specific GitLab issue by its IID. Returns full details including title, description, state, labels, assignees, and timestamps.';
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
            'issue_iid' => $schema
                ->integer()
                ->description('The issue IID (project-scoped issue number).')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $rejection = $this->accessChecker->check($request->integer('project_id'));

        if ($rejection !== null) {
            return $rejection;
        }

        try {
            $issue = $this->gitLab->getIssue(
                projectId: $request->integer('project_id'),
                issueIid: $request->integer('issue_iid'),
            );
        } catch (GitLabApiException $e) {
            return "Error reading issue: {$e->getMessage()}";
        }

        $iid = $issue['iid'] ?? '?';
        $title = $issue['title'] ?? 'Untitled';
        $state = $issue['state'] ?? 'unknown';
        $author = $issue['author']['username'] ?? 'unknown';
        $createdAt = $issue['created_at'] ?? '';
        $updatedAt = $issue['updated_at'] ?? '';
        $description = $issue['description'] ?? '';

        $labels = ! empty($issue['labels']) ? implode(', ', $issue['labels']) : 'none';

        $assignees = 'none';
        if (! empty($issue['assignees'])) {
            $assignees = implode(', ', array_map(
                fn (array $a) => '@'.($a['username'] ?? 'unknown'),
                $issue['assignees'],
            ));
        }

        $webUrl = $issue['web_url'] ?? '';

        $lines = [
            "Issue #{$iid}: {$title}",
            "State: {$state}",
            "Author: @{$author}",
            "Assignees: {$assignees}",
            "Labels: {$labels}",
            "Created: {$createdAt}",
            "Updated: {$updatedAt}",
        ];

        if ($webUrl !== '') {
            $lines[] = "URL: {$webUrl}";
        }

        if ($description !== '') {
            $lines[] = '';
            $lines[] = '--- Description ---';
            $lines[] = $description;
        }

        return implode("\n", $lines);
    }
}
