<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\FindingAcceptance;
use App\Models\Task;
use App\Services\AcceptanceTrackingService;
use App\Services\GitLabClient;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Process acceptance tracking on MR merge.
 *
 * Final classification of all AI discussion threads: resolved → accepted,
 * unresolved → dismissed. Detects bulk resolution for over-reliance signal.
 *
 * @see §16.2 Acceptance Tracking
 */
class ProcessAcceptanceTracking implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $projectId,
        public readonly int $gitlabProjectId,
        public readonly int $mrIid,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(GitLabClient $gitLab): void
    {
        $service = new AcceptanceTrackingService();

        // Find all completed code review tasks for this MR
        $tasks = Task::where('project_id', $this->projectId)
            ->where('mr_iid', $this->mrIid)
            ->where('type', TaskType::CodeReview)
            ->where('status', TaskStatus::Completed)
            ->get();

        if ($tasks->isEmpty()) {
            Log::info('ProcessAcceptanceTracking: no completed review tasks for MR', [
                'project_id' => $this->projectId,
                'mr_iid' => $this->mrIid,
            ]);

            return;
        }

        // Fetch all discussions from GitLab
        try {
            $discussions = $gitLab->listMergeRequestDiscussions(
                $this->gitlabProjectId,
                $this->mrIid,
            );
        } catch (\Throwable $e) {
            Log::warning('ProcessAcceptanceTracking: failed to fetch discussions', [
                'project_id' => $this->projectId,
                'mr_iid' => $this->mrIid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Filter to AI-created discussions only
        $aiDiscussions = array_filter(
            $discussions,
            fn (array $d) => $service->isAiCreatedDiscussion($d),
        );

        // Detect bulk resolution across all AI threads
        $isBulkResolved = $service->detectBulkResolution($aiDiscussions);

        // Process each task's findings
        foreach ($tasks as $task) {
            $findings = $task->result['findings'] ?? [];

            foreach ($findings as $finding) {
                // Only track findings that had inline threads (critical/major)
                if (! in_array($finding['severity'], ['critical', 'major'], true)) {
                    continue;
                }

                $discussionId = $service->matchFindingToDiscussion($finding, $aiDiscussions);

                // Classify the thread state
                $status = 'dismissed'; // default if no matching discussion found
                if ($discussionId !== null) {
                    $matchedDiscussion = collect($aiDiscussions)
                        ->first(fn (array $d) => ($d['id'] ?? null) === $discussionId);

                    if ($matchedDiscussion !== null) {
                        $status = $service->classifyThreadState($matchedDiscussion);
                    }
                }

                FindingAcceptance::updateOrCreate(
                    [
                        'task_id' => $task->id,
                        'finding_id' => (string) $finding['id'],
                    ],
                    [
                        'project_id' => $this->projectId,
                        'mr_iid' => $this->mrIid,
                        'file' => $finding['file'],
                        'line' => $finding['line'],
                        'severity' => $finding['severity'],
                        'title' => $finding['title'],
                        'gitlab_discussion_id' => $discussionId,
                        'status' => $status,
                        'bulk_resolved' => $isBulkResolved,
                    ],
                );
            }
        }

        $totalRecords = FindingAcceptance::where('project_id', $this->projectId)
            ->where('mr_iid', $this->mrIid)
            ->count();

        Log::info('ProcessAcceptanceTracking: completed', [
            'project_id' => $this->projectId,
            'mr_iid' => $this->mrIid,
            'findings_tracked' => $totalRecords,
            'bulk_resolved' => $isBulkResolved,
        ]);
    }
}
