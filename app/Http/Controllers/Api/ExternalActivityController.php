<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExternalActivityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['nullable', 'string', 'in:code_review,feature_dev,ui_adjustment,prd_creation,issue_discussion,security_audit,deep_analysis'],
            'project_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $accessibleProjectIds = $user->accessibleProjects()->pluck('id');

        $query = Task::with(['project:id,name', 'user:id,name,avatar_url'])
            ->whereIn('project_id', $accessibleProjectIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $projectId = $request->input('project_id');
        if ($projectId !== null && $projectId !== '') {
            if ($accessibleProjectIds->contains((int) $projectId)) {
                $query->where('project_id', (int) $projectId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $type = $request->input('type');
        if (is_string($type) && $type !== '') {
            $query->where('type', $type);
        }

        $perPage = $request->integer('per_page', 25);

        return ActivityResource::collection(
            $query->cursorPaginate($perPage)
        );
    }
}
