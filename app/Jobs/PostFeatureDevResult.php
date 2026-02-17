<?php

namespace App\Jobs;

use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\Task;
use App\Services\GitLabClient;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Create a merge request and post a summary comment for a feature dev task.
 *
 * After the executor pushes code to a feature branch, this job:
 * 1. Creates a merge request via the GitLab API (using bot PAT)
 * 2. Posts a summary comment on the originating Issue
 *
 * The executor cannot create MRs directly — it runs in a sandboxed GitLab Runner
 * with only the CI trigger token. MR creation requires the bot account's PAT.
 *
 * @see T44: Feature development — ai::develop label
 */
class PostFeatureDevResult implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(
        public readonly int $taskId,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RetryWithBackoff];
    }

    public function handle(GitLabClient $gitLab): void
    {
        $task = Task::with('project')->find($this->taskId);

        if ($task === null) {
            Log::warning('PostFeatureDevResult: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->result === null) {
            Log::info('PostFeatureDevResult: task has no result, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $result = $task->result;
        $gitlabProjectId = $task->project->gitlab_project_id;

        // Step 1: Create or update merge request
        $mrIid = $this->createMergeRequest($gitLab, $task, $result, $gitlabProjectId);

        if ($mrIid === null) {
            return;
        }

        // Step 2: Post summary on the originating Issue (if applicable)
        if ($task->issue_iid !== null) {
            $this->postIssueSummary($gitLab, $task, $result, $gitlabProjectId, $mrIid);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function createMergeRequest(GitLabClient $gitLab, Task $task, array $result, int $gitlabProjectId): ?int
    {
        $branch = $result['branch'] ?? null;
        $mrTitle = $result['mr_title'] ?? null;
        $mrDescription = $result['mr_description'] ?? null;

        if (empty($branch) || empty($mrTitle)) {
            Log::warning('PostFeatureDevResult: missing branch or mr_title in result', [
                'task_id' => $this->taskId,
            ]);

            return null;
        }

        // T72: If this task targets an existing MR (designer iteration), update it
        $existingMrIid = $result['existing_mr_iid'] ?? null;
        if ($existingMrIid !== null && $task->mr_iid !== null) {
            return $this->updateExistingMergeRequest(
                $gitLab, $task, $mrTitle, $mrDescription, $gitlabProjectId
            );
        }

        try {
            $mr = $gitLab->createMergeRequest($gitlabProjectId, [
                'source_branch' => $branch,
                'target_branch' => 'main',
                'title' => $mrTitle,
                'description' => $mrDescription ?? '',
            ]);

            $mrIid = (int) $mr['iid'];

            $task->mr_iid = $mrIid;
            $task->save();

            Log::info('PostFeatureDevResult: merge request created', [
                'task_id' => $this->taskId,
                'mr_iid' => $mrIid,
                'branch' => $branch,
            ]);

            return $mrIid;
        } catch (Throwable $e) {
            Log::warning('PostFeatureDevResult: failed to create merge request', [
                'task_id' => $this->taskId,
                'branch' => $branch,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update an existing MR (designer iteration flow, T72).
     *
     * The executor already pushed new commits to the same branch.
     * We update the MR title/description to reflect the correction.
     */
    private function updateExistingMergeRequest(
        GitLabClient $gitLab,
        Task $task,
        string $mrTitle,
        ?string $mrDescription,
        int $gitlabProjectId,
    ): int {
        $mrIid = $task->mr_iid;

        // This method is only called when mr_iid is non-null (guarded by caller)
        assert($mrIid !== null);

        try {
            $gitLab->updateMergeRequest($gitlabProjectId, $mrIid, array_filter([
                'title' => $mrTitle,
                'description' => $mrDescription,
            ]));

            Log::info('PostFeatureDevResult: existing MR updated (designer iteration)', [
                'task_id' => $this->taskId,
                'mr_iid' => $mrIid,
            ]);

            return $mrIid;
        } catch (Throwable $e) {
            Log::warning('PostFeatureDevResult: failed to update existing MR', [
                'task_id' => $this->taskId,
                'mr_iid' => $mrIid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function postIssueSummary(GitLabClient $gitLab, Task $task, array $result, int $gitlabProjectId, int $mrIid): void
    {
        $markdown = $this->formatSummary($result, $mrIid);

        // This method is only called when issue_iid is non-null (guarded by caller)
        $issueIid = $task->issue_iid;
        assert($issueIid !== null);

        try {
            $note = $gitLab->createIssueNote(
                $gitlabProjectId,
                $issueIid,
                $markdown,
            );

            $task->comment_id = $note['id'];
            $task->save();

            Log::info('PostFeatureDevResult: posted issue summary', [
                'task_id' => $this->taskId,
                'issue_iid' => $task->issue_iid,
                'note_id' => $note['id'],
            ]);
        } catch (Throwable $e) {
            Log::warning('PostFeatureDevResult: failed to post issue summary', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Format the feature dev result as a markdown summary for the Issue.
     *
     * @param  array<string, mixed>  $result
     */
    private function formatSummary(array $result, int $mrIid): string
    {
        $branch = $result['branch'] ?? 'unknown';
        $mrTitle = $result['mr_title'] ?? 'Untitled';
        $testsAdded = ($result['tests_added'] ?? false) ? '✅ Yes' : '❌ No';
        $notes = $result['notes'] ?? '';
        $filesChanged = $result['files_changed'] ?? [];

        $lines = [
            '### 🤖 AI Feature Development Complete',
            '',
            "**Merge Request:** !{$mrIid} — {$mrTitle}",
            "**Branch:** `{$branch}`",
            "**Tests Added:** {$testsAdded}",
            '',
        ];

        if (! empty($filesChanged)) {
            $lines[] = '**Files Changed:**';
            foreach ($filesChanged as $file) {
                $action = $file['action'] ?? 'modified';
                $path = $file['path'] ?? 'unknown';
                $summary = $file['summary'] ?? '';
                $icon = $action === 'created' ? '🆕' : '✏️';
                $lines[] = "- {$icon} `{$path}` — {$summary}";
            }
            $lines[] = '';
        }

        if (! empty($notes)) {
            $lines[] = '**Notes:**';
            $lines[] = $notes;
        }

        return implode("\n", $lines);
    }
}
