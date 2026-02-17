<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlertEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InfrastructureAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $alerts = AlertEvent::active()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $alerts]);
    }

    public function acknowledge(Request $request, AlertEvent $alertEvent): JsonResponse
    {
        $this->authorizeAdmin($request);

        $alertEvent->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $alertEvent->fresh()]);
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
            abort(403, 'Infrastructure alerts are restricted to administrators.');
        }
    }
}
