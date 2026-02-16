<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CostAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $alerts = CostAlert::active()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $alerts]);
    }

    public function acknowledge(Request $request, CostAlert $costAlert): JsonResponse
    {
        $this->authorizeAdmin($request);

        $costAlert->update([
            'acknowledged' => true,
            'acknowledged_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $costAlert->fresh()]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        $hasAdmin = $user->projects()
            ->where('enabled', true)
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Cost alerts are restricted to administrators.');
        }
    }
}
