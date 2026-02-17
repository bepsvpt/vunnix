<?php

namespace App\Agents\Tools;

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * AI SDK Tool: Resolve GitLab project members by username or name.
 *
 * Searches the project's member list (including inherited members from
 * parent groups) and returns matching users with their GitLab user IDs.
 * Used by the AI to resolve usernames to IDs for assignee_id in
 * DispatchAction, instead of fabricating numeric IDs.
 */
class ResolveGitLabUser implements Tool
{
    public function __construct(
        protected GitLabClient $gitLab,
        protected ProjectAccessChecker $accessChecker,
    ) {}

    public function description(): string
    {
        return 'Search for a GitLab user within a project\'s members by username or name. Returns matching users with their GitLab user IDs — use this to resolve assignee_id before dispatching actions.';
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
            'query' => $schema
                ->string()
                ->description('Username or name to search for (e.g. "john" or "John Doe").')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $rejection = $this->accessChecker->check($request->integer('project_id'));

        if ($rejection !== null) {
            return $rejection;
        }

        $query = (string) $request->string('query');
        if ($query === '') {
            return 'Error: query parameter is required.';
        }

        try {
            $members = $this->gitLab->listProjectMembers(
                projectId: $request->integer('project_id'),
                params: ['query' => $query, 'per_page' => 10],
            );
        } catch (GitLabApiException $e) {
            return "Error searching project members: {$e->getMessage()}";
        }

        if ($members === []) {
            return "No project members found matching \"{$query}\".";
        }

        $lines = [];
        foreach ($members as $member) {
            $id = $member['id'] ?? '?';
            $username = $member['username'] ?? 'unknown';
            $name = $member['name'] ?? '';

            $lines[] = "- @{$username} (ID: {$id}) — {$name}";
        }

        $count = count($members);

        return "Found {$count} member(s) matching \"{$query}\":\n\n".implode("\n", $lines);
    }
}
