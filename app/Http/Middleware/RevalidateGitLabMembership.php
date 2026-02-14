<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RevalidateGitLabMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $user = auth()->user();
            $cacheKey = "membership_revalidated:{$user->id}";

            if (! Cache::has($cacheKey)) {
                $user->syncMemberships();
                Cache::put($cacheKey, true, 900); // 15 minutes
            }
        }

        return $next($request);
    }
}
