<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskOrigin;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExternalTaskResource;
use App\Jobs\ProcessTask;
use App\Models\Project;
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

        /** @var \App\Models\User $user */
        $user = $request->user();

        $reviewableProjectIds = $user->accessibleProjects()
            ->filter(fn (Project $project): bool => $user->hasPermission('review.view', $project))
            ->pluck('id');

        $query = Task::with(['project:id,name', 'user:id,name'])
            ->whereIn('project_id', $reviewableProjectIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $projectId = $request->input('project_id');
        if ($projectId !== null && $projectId !== '') {
            if ($reviewableProjectIds->contains((int) $projectId)) {
                $query->where('project_id', (int) $projectId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $type = $request->input('type');
        if (is_string($type) && $type !== '') {
            $query->where('type', $type);
        }

        $status = $request->input('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $dateFrom = $request->input('date_from');
        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->where('created_at', '>=', $dateFrom);
        }

        $dateTo = $request->input('date_to');
        if (is_string($dateTo) && $dateTo !== '') {
            $query->where('created_at', '<=', $dateTo.' 23:59:59');
        }

        $promptVersion = $request->input('prompt_version');
        if (is_string($promptVersion) && $promptVersion !== '') {
            $query->whereJsonContains('prompt_version->skill', $promptVersion);
        }

        $perPage = $request->integer('per_page', 25);

        return ExternalTaskResource::collection(
            $query->cursorPaginate($perPage)
        );
    }

    public function show(Request $request, Task $task): ExternalTaskResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $projectId = $task->project_id;
        if (! $user->accessibleProjects()->pluck('id')->contains($projectId)) {
            abort(403, 'You do not have access to this task.');
        }

        $project = Project::query()->find($projectId);
        /** @var Project $project */
        if (! $user->hasPermission('review.view', $project)) {
            abort(403, 'Review view permission required.');
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

        /** @var \App\Models\User $user */
        $user = $request->user();

        $project = Project::findOrFail((int) $validated['project_id']);
        $accessibleProjectIds = $user->accessibleProjects()->pluck('id');

        if (! $accessibleProjectIds->contains($project->id)) {
            abort(403, 'You do not have access to this project.');
        }

        if (! $user->hasPermission('review.trigger', $project)) {
            abort(403, 'Review trigger permission required.');
        }

        $task = Task::create([
            'type' => TaskType::CodeReview,
            'origin' => TaskOrigin::Webhook,
            'user_id' => $user->id,
            'project_id' => $project->id,
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
