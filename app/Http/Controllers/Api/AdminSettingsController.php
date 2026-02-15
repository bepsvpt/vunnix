<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GlobalSettingResource;
use App\Models\GlobalSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSettingsAdmin($request);

        $settings = GlobalSetting::orderBy('key')->get();

        return response()->json([
            'data' => GlobalSettingResource::collection($settings),
            'api_key_configured' => ! empty(config('services.anthropic.api_key') ?: env('ANTHROPIC_API_KEY')),
            'defaults' => GlobalSetting::defaults(),
        ]);
    }

    private function authorizeSettingsAdmin(Request $request): void
    {
        $user = $request->user();

        $hasAdmin = $user->projects()
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Settings management access required.');
        }
    }
}
