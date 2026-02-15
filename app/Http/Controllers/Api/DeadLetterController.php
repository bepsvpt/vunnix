<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeadLetterEntry;
use App\Services\DeadLetterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeadLetterController extends Controller
{
    public function __construct(private readonly DeadLetterService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = DeadLetterEntry::active()
            ->with('task')
            ->when($request->filled('reason'), fn ($q) => $q->where('failure_reason', $request->input('reason')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('dead_lettered_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->where('dead_lettered_at', '<=', $request->input('date_to')))
            ->orderByDesc('dead_lettered_at')
            ->limit(50);

        // Filter by project — task_record is JSONB with project_id
        if ($request->filled('project_id')) {
            $projectId = (int) $request->input('project_id');
            if (DB::connection()->getDriverName() === 'sqlite') {
                $query->where('task_record', 'like', '%"project_id":' . $projectId . '%');
            } else {
                $query->whereRaw("(task_record->>'project_id')::int = ?", [$projectId]);
            }
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Request $request, DeadLetterEntry $deadLetterEntry): JsonResponse
    {
        $this->authorizeAdmin($request);

        $deadLetterEntry->load(['task', 'dismissedBy', 'retriedBy', 'retriedTask']);

        return response()->json(['data' => $deadLetterEntry]);
    }

    public function retry(Request $request, DeadLetterEntry $deadLetterEntry): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $newTask = $this->service->retry($deadLetterEntry, $request->user());
            return response()->json(['success' => true, 'data' => $newTask]);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function dismiss(Request $request, DeadLetterEntry $deadLetterEntry): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $this->service->dismiss($deadLetterEntry, $request->user());
            return response()->json(['success' => true]);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        $hasAdmin = $user->projects()
            ->where('enabled', true)
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Dead letter queue access is restricted to administrators.');
        }
    }
}
