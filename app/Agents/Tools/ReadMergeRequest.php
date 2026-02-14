<?php

namespace App\Agents\Tools;

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * AI SDK Tool: Read a specific GitLab merge request.
 *
 * Returns the full details and description of a single merge request.
 * Wraps GitLabClient::getMergeRequest() for use by the Conversation Engine.
 *
 * @see §14.2 — Tool catalog: ReadMergeRequest
 */
class ReadMergeRequest implements Tool
{
    public function __construct(
        protected GitLabClient $gitLab,
        protected ProjectAccessChecker $accessChecker,
    ) {}

    public function description(): string
    {
        return 'Read a specific GitLab merge request by its IID. Returns full details including title, description, state, branches, labels, assignees, and merge status.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema
                ->integer()
                ->description('The GitLab project ID.')
                ->required(),
            'mr_iid' => $schema
                ->integer()
                ->description('The merge request IID (project-scoped MR number).')
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
            $mr = $this->gitLab->getMergeRequest(
                projectId: $request->integer('project_id'),
                mrIid: $request->integer('mr_iid'),
            );
        } catch (GitLabApiException $e) {
            return "Error reading merge request: {$e->getMessage()}";
        }

        $iid = $mr['iid'] ?? '?';
        $title = $mr['title'] ?? 'Untitled';
        $state = $mr['state'] ?? 'unknown';
        $author = $mr['author']['username'] ?? 'unknown';
        $sourceBranch = $mr['source_branch'] ?? '?';
        $targetBranch = $mr['target_branch'] ?? '?';
        $mergeStatus = $mr['merge_status'] ?? 'unknown';
        $createdAt = $mr['created_at'] ?? '';
        $updatedAt = $mr['updated_at'] ?? '';
        $description = $mr['description'] ?? '';

        $labels = ! empty($mr['labels']) ? implode(', ', $mr['labels']) : 'none';

        $assignees = 'none';
        if (! empty($mr['assignees'])) {
            $assignees = implode(', ', array_map(
                fn (array $a) => '@'.($a['username'] ?? 'unknown'),
                $mr['assignees'],
            ));
        }

        $reviewers = 'none';
        if (! empty($mr['reviewers'])) {
            $reviewers = implode(', ', array_map(
                fn (array $r) => '@'.($r['username'] ?? 'unknown'),
                $mr['reviewers'],
            ));
        }

        $webUrl = $mr['web_url'] ?? '';

        $lines = [
            "MR !{$iid}: {$title}",
            "State: {$state}",
            "Author: @{$author}",
            "Branches: {$sourceBranch} → {$targetBranch}",
            "Merge status: {$mergeStatus}",
            "Assignees: {$assignees}",
            "Reviewers: {$reviewers}",
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
