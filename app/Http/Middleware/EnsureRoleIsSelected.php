<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureRoleIsSelected
{
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();
    if (! $user) {
        return $next($request);
    }
    if ($user->{User::TYPE_ID} !== null) {
        return $next($request);
    }
    $token = $user->currentAccessToken();

    if ($token && $token instanceof PersonalAccessToken) {
        if ($token->created_at->diffInMinutes(now()) > 15) {
            $user->tokens()->delete();
            return response()->json(['message' => __('auth.role_selection_timeout')], 401);
        }
    }

    return response()->json([
        'message' => 'لطفاً ابتدا نقش کاربری خود را انتخاب کنید.',
        'code'    => 'ROLE_NOT_SELECTED'
    ], 403);
}
}
