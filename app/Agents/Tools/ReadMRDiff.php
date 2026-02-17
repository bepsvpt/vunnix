<?php

namespace App\Agents\Tools;

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * AI SDK Tool: Read merge request file changes (diff).
 *
 * Returns the diff hunks for each changed file in a merge request.
 * Wraps GitLabClient::getMergeRequestChanges() for use by the Conversation Engine.
 *
 * @see §14.2 — Tool catalog: ReadMRDiff
 */
class ReadMRDiff implements Tool
{
    /**
     * Maximum total output size in characters before truncation.
     * Large MR diffs can overwhelm the AI context window.
     */
    private const MAX_OUTPUT_SIZE = 100_000;

    public function __construct(
        protected GitLabClient $gitLab,
        protected ProjectAccessChecker $accessChecker,
    ) {}

    public function description(): string
    {
        return 'Read the file changes (diff) of a GitLab merge request. Returns the diff hunks for each changed file.';
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
            $data = $this->gitLab->getMergeRequestChanges(
                projectId: $request->integer('project_id'),
                mrIid: $request->integer('mr_iid'),
            );
        } catch (GitLabApiException $e) {
            return "Error reading merge request diff: {$e->getMessage()}";
        }

        $changes = $data['changes'] ?? [];

        if (empty($changes)) {
            return 'No file changes found in this merge request.';
        }

        $lines = [];
        $totalSize = 0;
        $fileCount = count($changes);
        $filesIncluded = 0;

        foreach ($changes as $change) {
            $oldPath = $change['old_path'] ?? '';
            $newPath = $change['new_path'] ?? '';
            $isNewFile = $change['new_file'] ?? false;
            $isDeletedFile = $change['deleted_file'] ?? false;
            $isRenamedFile = $change['renamed_file'] ?? false;
            $diff = $change['diff'] ?? '';

            // Build file header
            if ($isNewFile) {
                $header = "── New file: {$newPath} ──";
            } elseif ($isDeletedFile) {
                $header = "── Deleted file: {$oldPath} ──";
            } elseif ($isRenamedFile) {
                $header = "── Renamed: {$oldPath} → {$newPath} ──";
            } else {
                $header = "── Modified: {$newPath} ──";
            }

            $entry = $header."\n".$diff;
            $entrySize = strlen($entry);

            if ($totalSize + $entrySize > self::MAX_OUTPUT_SIZE) {
                $remaining = $fileCount - $filesIncluded;
                $lines[] = "\n[Output truncated — {$remaining} more file(s) not shown. Use BrowseRepoTree or ReadFile to inspect specific files.]";

                break;
            }

            $lines[] = $entry;
            $totalSize += $entrySize;
            $filesIncluded++;
        }

        return "MR diff — {$fileCount} file(s) changed:\n\n".implode("\n\n", $lines);
    }
}
