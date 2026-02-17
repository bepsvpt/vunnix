<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if ($bearerToken === null || $bearerToken === '') {
            return response()->json(['error' => 'Missing API key.'], 401);
        }

        $user = $this->apiKeyService->resolveUser($bearerToken, $request->ip());

        if (! $user instanceof \App\Models\User) {
            Log::warning('API key authentication failed', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid or expired API key.'], 401);
        }

        // Set the authenticated user on the request
        $request->setUserResolver(fn (): \App\Models\User => $user);
        $request->attributes->set('auth_via', 'api_key');

        return $next($request);
    }
}
