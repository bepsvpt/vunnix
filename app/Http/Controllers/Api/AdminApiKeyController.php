<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $keys = ApiKey::with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiKey $key): array => [
                'id' => $key->id,
                'user' => [
                    'id' => $key->user->id,
                    'name' => $key->user->name,
                    'email' => $key->user->email,
                ],
                'name' => $key->name,
                'last_used_at' => $key->last_used_at?->toISOString(),
                'last_ip' => $key->last_ip,
                'expires_at' => $key->expires_at?->toISOString(),
                'revoked' => $key->revoked,
                'created_at' => $key->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $keys]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $apiKey = ApiKey::findOrFail($id);
        $this->apiKeyService->revoke($apiKey);

        return response()->json(['message' => 'API key revoked.']);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }
        $hasAdminPerm = $user->roles()
            ->whereHas('permissions', fn ($q) => $q->where('name', 'admin.global_config'))
            ->exists();

        if (! $hasAdminPerm) {
            abort(403, 'Admin permission required.');
        }
    }
}
