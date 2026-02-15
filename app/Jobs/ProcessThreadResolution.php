<?php

namespace App\Jobs;

use App\Models\FindingAcceptance;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Update acceptance tracking when a discussion thread is resolved/unresolved.
 *
 * Triggered by MR update webhooks that contain thread resolution changes (D149).
 * Provides near-real-time tracking before MR merge.
 */
class ProcessThreadResolution implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $projectId,
        public readonly int $mrIid,
        public readonly string $discussionId,
        public readonly bool $resolved,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(): void
    {
        $acceptance = FindingAcceptance::where('project_id', $this->projectId)
            ->where('mr_iid', $this->mrIid)
            ->where('gitlab_discussion_id', $this->discussionId)
            ->first();

        if ($acceptance === null) {
            Log::debug('ProcessThreadResolution: no matching acceptance record', [
                'project_id' => $this->projectId,
                'mr_iid' => $this->mrIid,
                'discussion_id' => $this->discussionId,
            ]);

            return;
        }

        $acceptance->update([
            'status' => $this->resolved ? 'accepted' : 'pending',
            'resolved_at' => $this->resolved ? now() : null,
        ]);

        Log::info('ProcessThreadResolution: updated acceptance', [
            'finding_acceptance_id' => $acceptance->id,
            'status' => $acceptance->status,
            'discussion_id' => $this->discussionId,
        ]);
    }
}
