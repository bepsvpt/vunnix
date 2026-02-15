<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GlobalSettingResource;
use App\Models\GlobalSetting;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Admin\UpdateSettingsRequest;
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

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $this->authorizeSettingsAdmin($request);

        foreach ($request->validated()['settings'] as $item) {
            $key = $item['key'];
            $value = $item['value'];
            $type = $item['type'] ?? 'string';

            if ($key === 'bot_pat_created_at') {
                GlobalSetting::updateOrCreate(
                    ['key' => $key],
                    ['bot_pat_created_at' => $value, 'value' => $value, 'type' => 'string']
                );
                continue;
            }

            GlobalSetting::set($key, $value, $type);
        }

        $settings = GlobalSetting::orderBy('key')->get();

        return response()->json([
            'success' => true,
            'data' => GlobalSettingResource::collection($settings),
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
