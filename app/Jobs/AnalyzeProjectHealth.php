<?php

namespace App\Jobs;

use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\Project;
use App\Services\Health\HealthAnalysisService;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeProjectHealth implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $projectId,
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

    public function handle(HealthAnalysisService $analysisService): void
    {
        $project = Project::query()->find($this->projectId);
        if (! $project instanceof Project) {
            Log::warning('AnalyzeProjectHealth: project not found', ['project_id' => $this->projectId]);

            return;
        }

        try {
            $analysisService->analyzeProject($project);
        } catch (Throwable $e) {
            Log::warning('AnalyzeProjectHealth: analysis failed', [
                'project_id' => $this->projectId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
