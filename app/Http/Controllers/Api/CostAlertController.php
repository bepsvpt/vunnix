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
        if ($user === null) {
            abort(401);
        }

        if (! $user->isGlobalAdmin()) {
            abort(403, 'Cost alerts are restricted to administrators.');
        }
    }
}
