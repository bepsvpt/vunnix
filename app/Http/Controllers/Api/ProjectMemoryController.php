<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListMemoryEntriesRequest;
use App\Http\Resources\MemoryEntryResource;
use App\Http\Resources\ProjectMemoryStatsResource;
use App\Models\MemoryEntry;
use App\Models\Project;
use App\Services\ProjectMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProjectMemoryController extends Controller
{
    public function __construct(
        private readonly ProjectMemoryService $projectMemoryService,
    ) {}

    public function index(ListMemoryEntriesRequest $request, Project $project): AnonymousResourceCollection
    {
        $this->authorizeProjectAdmin($request->user(), $project);

        $query = MemoryEntry::query()
            ->forProject($project->id)
            ->active()
            ->orderByDesc('confidence')
            ->orderByDesc('id');

        $type = $request->validated('type');
        if (is_string($type) && $type !== '') {
            $query->ofType($type);
        }

        $category = $request->validated('category');
        if (is_string($category) && $category !== '') {
            $query->where('category', $category);
        }

        return MemoryEntryResource::collection($query->cursorPaginate(25));
    }

    public function stats(ListMemoryEntriesRequest $request, Project $project): JsonResponse
    {
        $this->authorizeProjectAdmin($request->user(), $project);

        $stats = $this->projectMemoryService->getStats($project);

        return response()->json([
            'data' => new ProjectMemoryStatsResource($stats),
        ]);
    }

    public function destroy(ListMemoryEntriesRequest $request, Project $project, MemoryEntry $memoryEntry): JsonResponse
    {
        $this->authorizeProjectAdmin($request->user(), $project);

        if ($memoryEntry->project_id !== $project->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->projectMemoryService->deleteEntry($memoryEntry);

        return response()->json(['success' => true]);
    }

    private function authorizeProjectAdmin(?\App\Models\User $user, Project $project): void
    {
        if (! $user instanceof \App\Models\User) {
            abort(401);
        }

        if (! $user->hasPermission('admin.global_config', $project)) {
            abort(403, 'Project admin access required.');
        }
    }
}
