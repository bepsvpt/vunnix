<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateApiKeyRequest;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $keys = $user
            ->apiKeys()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'last_used_at' => $key->last_used_at?->toISOString(),
                'last_ip' => $key->last_ip,
                'expires_at' => $key->expires_at?->toISOString(),
                'revoked' => $key->revoked,
                'created_at' => $key->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $keys]);
    }

    public function store(CreateApiKeyRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $expiresAt = $request->validated('expires_at')
            ? Carbon::parse($request->validated('expires_at'))
            : null;

        $result = $this->apiKeyService->generate(
            $user,
            $request->validated('name'),
            $expiresAt,
        );

        return response()->json([
            'data' => [
                'id' => $result['api_key']->id,
                'name' => $result['api_key']->name,
                'created_at' => $result['api_key']->created_at->toISOString(),
            ],
            'plaintext' => $result['plaintext'],
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $apiKey = ApiKey::findOrFail($id);

        if ($apiKey->user_id !== $user->id) {
            abort(403, 'You can only revoke your own API keys.');
        }

        $this->apiKeyService->revoke($apiKey);

        return response()->json(['message' => 'API key revoked.']);
    }
}
