<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsEliteYAR
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return error(__('auth.unauthenticated'), [], 401);
        }
        $user->loadMissing('type');
        $isExpertType = $user->type && $user->type->slug === 'elite_yar';
        $hasExpertRole = $user->hasRole(['engineer', 'contractor']);

        if ($isExpertType || $hasExpertRole) {
            return $next($request);
        }

        return error(__('auth.forbidden'), [], 403);
    }
}
