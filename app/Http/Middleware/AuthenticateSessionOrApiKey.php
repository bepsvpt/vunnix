<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSessionOrApiKey
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Try session auth first (Vue SPA)
        if ($request->user()) {
            $request->attributes->set('auth_via', 'session');

            return $next($request);
        }

        // Try API key auth
        $bearerToken = $request->bearerToken();

        if (empty($bearerToken)) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        $user = $this->apiKeyService->resolveUser($bearerToken, $request->ip());

        if (! $user instanceof \App\Models\User) {
            return response()->json(['error' => 'Invalid or expired API key.'], 401);
        }

        $request->setUserResolver(fn (): \App\Models\User => $user);
        $request->attributes->set('auth_via', 'api_key');

        return $next($request);
    }
}
