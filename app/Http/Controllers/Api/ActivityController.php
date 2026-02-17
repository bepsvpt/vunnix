<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['nullable', 'string', 'in:code_review,feature_dev,ui_adjustment,prd_creation'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $projectIds = $user
            ->projects()
            ->where('enabled', true)
            ->pluck('projects.id');

        $query = Task::with(['project:id,name', 'user:id,name,avatar_url'])
            ->whereIn('project_id', $projectIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $type = $request->input('type');
        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }

        $perPage = $request->integer('per_page', 25);

        return ActivityResource::collection(
            $query->cursorPaginate($perPage)
        );
    }
}
