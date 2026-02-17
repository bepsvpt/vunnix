<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\FindingAcceptance;
use App\Models\Task;
use App\Services\AcceptanceTrackingService;
use App\Services\EngineerFeedbackService;
use App\Services\GitLabClient;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Process acceptance tracking on MR merge.
 *
 * Final classification of all AI discussion threads: resolved → accepted,
 * unresolved → dismissed. Detects bulk resolution for over-reliance signal.
 * Collects engineer emoji feedback (T87) for quality signal aggregation.
 *
 * @see §16.2 Acceptance Tracking
 * @see §16.3 Engineer Feedback
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
        $acceptanceService = new AcceptanceTrackingService;
        $feedbackService = new EngineerFeedbackService;

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
        } catch (Throwable $e) {
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
            fn (array $d) => $acceptanceService->isAiCreatedDiscussion($d),
        );

        // Detect bulk resolution across all AI threads
        $isBulkResolved = $acceptanceService->detectBulkResolution($aiDiscussions);

        // T87: Collect emoji reactions for each AI discussion's first note
        $emojiByDiscussion = $this->collectEmojiReactions($gitLab, $feedbackService, $aiDiscussions);

        // Process each task's findings
        foreach ($tasks as $task) {
            $findings = $task->result['findings'] ?? [];

            foreach ($findings as $finding) {
                // Only track findings that had inline threads (critical/major)
                if (! in_array($finding['severity'], ['critical', 'major'], true)) {
                    continue;
                }

                $discussionId = $acceptanceService->matchFindingToDiscussion($finding, $aiDiscussions);

                // Classify the thread state
                $status = 'dismissed'; // default if no matching discussion found
                if ($discussionId !== null) {
                    $matchedDiscussion = collect($aiDiscussions)
                        ->first(fn (array $d) => ($d['id'] ?? null) === $discussionId);

                    if ($matchedDiscussion !== null) {
                        $status = $acceptanceService->classifyThreadState($matchedDiscussion);
                    }
                }

                // T87: Get emoji feedback for this discussion
                $emojiResult = $emojiByDiscussion[$discussionId] ?? [
                    'positive_count' => 0,
                    'negative_count' => 0,
                    'sentiment' => 'neutral',
                ];

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
                        'category' => $finding['category'] ?? null,
                        'gitlab_discussion_id' => $discussionId,
                        'status' => $status,
                        'bulk_resolved' => $isBulkResolved,
                        'emoji_positive_count' => $emojiResult['positive_count'],
                        'emoji_negative_count' => $emojiResult['negative_count'],
                        'emoji_sentiment' => $emojiResult['sentiment'],
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

    /**
     * Collect emoji reactions for all AI discussion first notes.
     *
     * Returns a map of discussion_id => emoji classification result.
     * Failures for individual notes are logged and treated as neutral.
     *
     * @param  array<int, array>  $aiDiscussions
     * @return array<string, array{positive_count: int, negative_count: int, sentiment: string}>
     */
    private function collectEmojiReactions(
        GitLabClient $gitLab,
        EngineerFeedbackService $feedbackService,
        array $aiDiscussions,
    ): array {
        $emojiByDiscussion = [];

        foreach ($aiDiscussions as $discussion) {
            $discussionId = $discussion['id'] ?? null;
            $noteId = $discussion['notes'][0]['id'] ?? null;

            if ($discussionId === null || $noteId === null) {
                continue;
            }

            try {
                $emoji = $gitLab->listNoteAwardEmoji(
                    $this->gitlabProjectId,
                    $this->mrIid,
                    $discussionId,
                    (int) $noteId,
                );

                $emojiByDiscussion[$discussionId] = $feedbackService->classifyReactions($emoji);
            } catch (Throwable $e) {
                Log::warning('ProcessAcceptanceTracking: failed to fetch emoji for discussion', [
                    'project_id' => $this->projectId,
                    'mr_iid' => $this->mrIid,
                    'discussion_id' => $discussionId,
                    'error' => $e->getMessage(),
                ]);

                $emojiByDiscussion[$discussionId] = [
                    'positive_count' => 0,
                    'negative_count' => 0,
                    'sentiment' => 'neutral',
                ];
            }
        }

        return $emojiByDiscussion;
    }
}
