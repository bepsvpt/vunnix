<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskOrigin;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExternalTaskResource;
use App\Jobs\ProcessTask;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExternalTaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'project_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'in:code_review,feature_dev,ui_adjustment,prd_creation,issue_discussion,security_audit,deep_analysis'],
            'status' => ['nullable', 'string', 'in:received,queued,running,completed,failed,superseded'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'prompt_version' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        $accessibleProjectIds = $request->user()->accessibleProjects()->pluck('id');

        $query = Task::with(['project:id,name', 'user:id,name'])
            ->whereIn('project_id', $accessibleProjectIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($projectId = $request->input('project_id')) {
            if ($accessibleProjectIds->contains((int) $projectId)) {
                $query->where('project_id', (int) $projectId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('created_at', '<=', $dateTo.' 23:59:59');
        }

        if ($promptVersion = $request->input('prompt_version')) {
            $query->whereJsonContains('prompt_version->skill', $promptVersion);
        }

        $perPage = $request->integer('per_page', 25);

        return ExternalTaskResource::collection(
            $query->cursorPaginate($perPage)
        );
    }

    public function show(Request $request, Task $task): ExternalTaskResource
    {
        if (! $request->user()->accessibleProjects()->pluck('id')->contains($task->project_id)) {
            abort(403, 'You do not have access to this task.');
        }

        $task->load(['project:id,name', 'user:id,name']);

        return new ExternalTaskResource($task);
    }

    public function triggerReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'mr_iid' => ['required', 'integer'],
        ]);

        $accessibleProjectIds = $request->user()->accessibleProjects()->pluck('id');

        if (! $accessibleProjectIds->contains((int) $validated['project_id'])) {
            abort(403, 'You do not have access to this project.');
        }

        $task = Task::create([
            'type' => TaskType::CodeReview,
            'origin' => TaskOrigin::Webhook,
            'user_id' => $request->user()->id,
            'project_id' => (int) $validated['project_id'],
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Received,
            'mr_iid' => (int) $validated['mr_iid'],
            'result' => ['intent' => 'on_demand_review'],
        ]);

        $task->transitionTo(TaskStatus::Queued);

        $job = new ProcessTask($task->id);
        $job->resolveQueue($task);
        dispatch($job);

        $task->load(['project:id,name', 'user:id,name']);

        return (new ExternalTaskResource($task))
            ->response()
            ->setStatusCode(201);
    }
}
