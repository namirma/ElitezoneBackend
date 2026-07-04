<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsEmployer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return error(__('auth.unauthenticated'), [], 401);
        }

        if (! $user->hasRole('employer')) {
            return error(__('auth.forbidden'), [], 403);
        }

        return $next($request);
    }
}
