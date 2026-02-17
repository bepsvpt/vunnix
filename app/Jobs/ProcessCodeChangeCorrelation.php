<?php

namespace App\Jobs;

use App\Models\FindingAcceptance;
use App\Services\AcceptanceTrackingService;
use App\Services\GitLabClient;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Correlate push event code changes with existing AI findings.
 *
 * Per §16.2: If a finding targets file:line and the next push modifies
 * that region → strong acceptance signal.
 */
class ProcessCodeChangeCorrelation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $projectId,
        public readonly int $gitlabProjectId,
        public readonly int $mrIid,
        public readonly string $beforeSha,
        public readonly string $afterSha,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(GitLabClient $gitLab): void
    {
        $acceptances = FindingAcceptance::where('project_id', $this->projectId)
            ->where('mr_iid', $this->mrIid)
            ->where('code_change_correlated', false)
            ->get();

        if ($acceptances->isEmpty()) {
            Log::debug('ProcessCodeChangeCorrelation: no pending acceptances for MR', [
                'project_id' => $this->projectId,
                'mr_iid' => $this->mrIid,
            ]);

            return;
        }

        // Fetch diffs between before and after push SHAs
        try {
            $compare = $gitLab->compareBranches(
                $this->gitlabProjectId,
                $this->beforeSha,
                $this->afterSha,
            );
        } catch (Throwable $e) {
            Log::warning('ProcessCodeChangeCorrelation: failed to compare branches', [
                'project_id' => $this->projectId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $diffs = $compare['diffs'] ?? [];
        $service = new AcceptanceTrackingService;

        foreach ($acceptances as $acceptance) {
            $finding = [
                'file' => $acceptance->file,
                'line' => $acceptance->line,
                'end_line' => $acceptance->line, // best we have from stored data
            ];

            if ($service->correlateCodeChange($finding, $diffs)) {
                $acceptance->update([
                    'code_change_correlated' => true,
                    'correlated_commit_sha' => $this->afterSha,
                ]);

                Log::info('ProcessCodeChangeCorrelation: correlated code change', [
                    'finding_acceptance_id' => $acceptance->id,
                    'file' => $acceptance->file,
                    'line' => $acceptance->line,
                ]);
            }
        }
    }
}
