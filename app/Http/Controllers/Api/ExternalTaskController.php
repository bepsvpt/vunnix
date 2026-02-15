<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExternalTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tasks = Task::whereIn('project_id', $request->user()->accessibleProjects()->pluck('id'))
            ->orderByDesc('created_at')
            ->cursorPaginate($request->integer('per_page', 25));

        return response()->json($tasks);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        if (! $request->user()->accessibleProjects()->pluck('id')->contains($task->project_id)) {
            abort(403, 'You do not have access to this task.');
        }

        return response()->json(['data' => $task]);
    }
}
