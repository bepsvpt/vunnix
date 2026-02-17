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

        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $accessibleProjectIds = $user->accessibleProjects()->pluck('id');

        $query = Task::with(['project:id,name', 'user:id,name,avatar_url'])
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

        $perPage = $request->integer('per_page', 25);

        return ActivityResource::collection(
            $query->cursorPaginate($perPage)
        );
    }
}
