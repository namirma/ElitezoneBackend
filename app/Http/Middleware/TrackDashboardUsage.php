<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TrackDashboardUsage
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (Auth::check()) {
            $userId = Auth::id();
            $cacheKey = "user_dashboard_usage:{$userId}";

            $usage = Cache::get($cacheKey, []);

            if (! isset($usage[$module])) {
                $usage[$module] = 0;
            }
            $usage[$module]++;

            Cache::put($cacheKey, $usage, now()->addDays(60));
        }

        return $next($request);
    }
}
