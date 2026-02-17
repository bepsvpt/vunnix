<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Http\Resources\GlobalSettingResource;
use App\Models\GlobalSetting;
use App\Services\AuditLogService;
use App\Services\TeamChat\TeamChatNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSettingsAdmin($request);

        $settings = GlobalSetting::orderBy('key')->get();

        return response()->json([
            'data' => GlobalSettingResource::collection($settings),
            'api_key_configured' => config('services.anthropic.api_key') !== null && config('services.anthropic.api_key') !== '',
            'defaults' => GlobalSetting::defaults(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $this->authorizeSettingsAdmin($request);

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        foreach ($request->validated()['settings'] as $item) {
            $key = $item['key'];
            $value = $item['value'];
            $type = $item['type'] ?? 'string';

            $oldSetting = GlobalSetting::where('key', $key)->first();
            $oldValue = $oldSetting?->value;

            if ($key === 'bot_pat_created_at') {
                GlobalSetting::updateOrCreate(
                    ['key' => $key],
                    ['bot_pat_created_at' => $value, 'value' => $value, 'type' => 'string']
                );
            } else {
                GlobalSetting::set($key, $value, $type);
            }

            if ($oldValue !== $value) {
                try {
                    app(AuditLogService::class)->logConfigurationChange(
                        userId: $user->id,
                        key: $key,
                        oldValue: $oldValue,
                        newValue: $value,
                    );
                } catch (Throwable) {
                    // Audit logging should never break settings update
                }
            }
        }

        $settings = GlobalSetting::orderBy('key')->get();

        return response()->json([
            'success' => true,
            'data' => GlobalSettingResource::collection($settings),
        ]);
    }

    public function testWebhook(Request $request): JsonResponse
    {
        $this->authorizeSettingsAdmin($request);

        $request->validate([
            'webhook_url' => ['required', 'url', 'max:1000'],
            'platform' => ['required', 'string', 'in:slack,mattermost,google_chat,generic'],
        ]);

        $service = new TeamChatNotificationService;
        $success = $service->sendTest($request->input('webhook_url'), $request->input('platform'));

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Test notification sent successfully.' : 'Failed to send test notification. Check the webhook URL.',
        ]);
    }

    private function authorizeSettingsAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $hasAdmin = $user->projects()
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Settings management access required.');
        }
    }
}
