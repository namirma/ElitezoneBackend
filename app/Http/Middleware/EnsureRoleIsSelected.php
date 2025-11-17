<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class EnsureRoleIsSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->{User::TYPE_ID} !== null) {
            return $next($request);
        }
        $token = $user->currentAccessToken();
        if (! $token) {
            return $next($request);
        }
        $tokenCreatedAt = $token->created_at;
        if ($tokenCreatedAt->diffInMinutes(now()) > 15) {
            $user->tokens()->delete();
            return error(__('auth.role_selection_timeout'), [], 401);
        }
        return $next($request);
    }
}
