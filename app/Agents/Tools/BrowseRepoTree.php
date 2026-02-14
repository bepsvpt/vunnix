<?php

namespace App\Agents\Tools;

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * AI SDK Tool: Browse a GitLab repository tree.
 *
 * Lists files and directories at a given path within a project repository.
 * Wraps GitLabClient::listTree() for use by the Conversation Engine.
 *
 * @see §14.2 — Tool catalog: BrowseRepoTree
 */
class BrowseRepoTree implements Tool
{
    public function __construct(
        protected GitLabClient $gitLab,
    ) {}

    public function description(): string
    {
        return 'List files and directories in a GitLab repository path. Returns names, types (tree/blob), and paths.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema
                ->integer()
                ->description('The GitLab project ID.')
                ->required(),
            'path' => $schema
                ->string()
                ->description('Directory path to browse (empty string for repository root).'),
            'ref' => $schema
                ->string()
                ->description('Branch name, tag, or commit SHA (defaults to "main").'),
            'recursive' => $schema
                ->boolean()
                ->description('Whether to list files recursively (defaults to false).'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $items = $this->gitLab->listTree(
                projectId: $request->integer('project_id'),
                path: $request->string('path', ''),
                ref: $request->string('ref', 'main'),
                recursive: $request->boolean('recursive', false),
            );
        } catch (GitLabApiException $e) {
            return "Error browsing repository: {$e->getMessage()}";
        }

        if (empty($items)) {
            return 'No files or directories found at this path.';
        }

        $lines = [];
        foreach ($items as $item) {
            $type = $item['type'] === 'tree' ? 'dir' : 'file';
            $lines[] = "[{$type}] {$item['path']}";
        }

        return implode("\n", $lines);
    }
}
