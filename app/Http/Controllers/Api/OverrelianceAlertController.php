<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OverrelianceAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverrelianceAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $alerts = OverrelianceAlert::active()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $alerts]);
    }

    public function acknowledge(Request $request, OverrelianceAlert $overrelianceAlert): JsonResponse
    {
        $this->authorizeAdmin($request);

        $overrelianceAlert->update([
            'acknowledged' => true,
            'acknowledged_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $overrelianceAlert->fresh()]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $hasAdmin = $user->projects()
            ->where('enabled', true)
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Over-reliance alerts are restricted to administrators.');
        }
    }
}
