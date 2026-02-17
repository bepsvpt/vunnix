<?php

namespace App\Agents\Tools;

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * AI SDK Tool: Search code across a GitLab repository.
 *
 * Performs project-scoped code search (blob search) via the GitLab Search API.
 * Returns matching file paths with code snippets.
 *
 * @see §14.2 — Tool catalog: SearchCode
 */
class SearchCode implements Tool
{
    public function __construct(
        protected GitLabClient $gitLab,
        protected ProjectAccessChecker $accessChecker,
    ) {}

    public function description(): string
    {
        return 'Search for code across a GitLab repository. Returns matching file paths and code snippets.';
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
            'search' => $schema
                ->string()
                ->description('The search query string (searches file content).')
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
            $results = $this->gitLab->searchCode(
                projectId: $request->integer('project_id'),
                query: $request->string('search'),
            );
        } catch (GitLabApiException $e) {
            return "Error searching code: {$e->getMessage()}";
        }

        if ($results === []) {
            return 'No code matches found for this search query.';
        }

        $lines = [];
        foreach ($results as $result) {
            $path = $result['path'];
            $startLine = $result['startline'];
            $data = $result['data'];

            // Trim excessive whitespace and ensure valid UTF-8 (GitLab may return non-UTF-8 content)
            $snippet = trim($data);
            if (! mb_check_encoding($snippet, 'UTF-8')) {
                $snippet = mb_convert_encoding($snippet, 'UTF-8', 'UTF-8');
            }
            if (strlen($snippet) > 500) {
                $snippet = mb_strcut($snippet, 0, 500, 'UTF-8').'…';
            }

            $lines[] = "--- {$path}".($startLine > 0 ? ":L{$startLine}" : '')." ---\n{$snippet}";
        }

        return implode("\n\n", $lines);
    }
}
