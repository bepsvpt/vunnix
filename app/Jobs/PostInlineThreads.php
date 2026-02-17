<?php

namespace App\Jobs;

use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\Task;
use App\Services\GitLabClient;
use App\Services\InlineThreadFormatter;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Post inline discussion threads (Layer 2) on a GitLab merge request.
 *
 * Creates one resolvable discussion thread per high/medium severity finding,
 * positioned on the specific diff line. Engineers can resolve threads individually.
 *
 * @see §4.5 3-Layer Comment Pattern — Layer 2
 */
class PostInlineThreads implements ShouldQueue
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
            Log::warning('PostInlineThreads: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->mr_iid === null) {
            Log::info('PostInlineThreads: task has no MR, skipping', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->result === null) {
            Log::info('PostInlineThreads: task has no result, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $formatter = new InlineThreadFormatter;
        $findings = $formatter->filterHighMedium($task->result['findings'] ?? []);

        if (empty($findings)) {
            Log::info('PostInlineThreads: no high/medium findings, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $projectId = $task->project->gitlab_project_id;

        // Fetch MR to get diff_refs for positioning
        try {
            $mr = $gitLab->getMergeRequest($projectId, $task->mr_iid);
        } catch (Throwable $e) {
            Log::warning('PostInlineThreads: failed to fetch MR', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $diffRefs = $mr['diff_refs'] ?? [];

        // T40: Fetch existing discussions to avoid duplicating threads (D33)
        $existingDiscussions = $this->fetchExistingDiscussions($gitLab, $projectId, $task->mr_iid);

        foreach ($findings as $finding) {
            // T40: Skip if this finding already has an unresolved discussion thread
            if ($this->hasExistingThread($finding, $existingDiscussions)) {
                Log::info('PostInlineThreads: skipping duplicate finding (D33)', [
                    'task_id' => $this->taskId,
                    'finding_id' => $finding['id'],
                    'file' => $finding['file'],
                    'line' => $finding['line'],
                ]);

                continue;
            }

            $body = $formatter->format($finding);

            $position = [
                'base_sha' => $diffRefs['base_sha'] ?? '',
                'start_sha' => $diffRefs['start_sha'] ?? '',
                'head_sha' => $diffRefs['head_sha'] ?? '',
                'position_type' => 'text',
                'new_path' => $finding['file'],
                'new_line' => $finding['line'],
            ];

            try {
                $gitLab->createMergeRequestDiscussion(
                    $projectId,
                    $task->mr_iid,
                    $body,
                    $position,
                );

                Log::info('PostInlineThreads: created thread', [
                    'task_id' => $this->taskId,
                    'finding_id' => $finding['id'],
                    'file' => $finding['file'],
                    'line' => $finding['line'],
                ]);
            } catch (Throwable $e) {
                Log::warning('PostInlineThreads: failed to create thread', [
                    'task_id' => $this->taskId,
                    'finding_id' => $finding['id'],
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    /**
     * Fetch existing discussion threads for an MR to check for duplicates.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchExistingDiscussions(GitLabClient $gitLab, int $projectId, int $mrIid): array
    {
        try {
            return $gitLab->listMergeRequestDiscussions($projectId, $mrIid);
        } catch (Throwable $e) {
            Log::warning('PostInlineThreads: failed to fetch discussions for dedup, proceeding without', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Check if a finding already has an unresolved discussion thread.
     *
     * Matches by file path and finding title in the discussion body.
     * Per D33: "Same issue = same discussion thread. No duplicate threads."
     *
     * @param  array<string, mixed>  $finding
     * @param  array<int, array<string, mixed>>  $discussions
     */
    private function hasExistingThread(array $finding, array $discussions): bool
    {
        foreach ($discussions as $discussion) {
            $notes = $discussion['notes'] ?? [];

            if (empty($notes)) {
                continue;
            }

            $firstNote = $notes[0];
            $body = $firstNote['body'] ?? '';
            $position = $firstNote['position'] ?? [];

            // Match by file path + finding title
            $sameFile = ($position['new_path'] ?? '') === $finding['file'];
            $sameTitle = str_contains($body, $finding['title']);

            if ($sameFile && $sameTitle) {
                return true;
            }
        }

        return false;
    }
}
