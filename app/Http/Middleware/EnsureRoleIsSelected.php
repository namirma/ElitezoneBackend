<?php

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoleIsSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->{User::TYPE_ID} !== null) {
            return $next($request);
        }

        // =========================================================================
        //  The 2-Hour Logic (طبق فلوچارت)
        // =========================================================================

        $registrationTime = Carbon::parse($user->{User::CREATED_AT});
        $hoursPassed = $registrationTime->diffInHours(now());
        $isWindowOpen = $hoursPassed < 2;

        $metaData = [
            'role_selected' => false,
            'hours_passed' => $hoursPassed,
            'registration_window_open' => $isWindowOpen,
        ];

        if (! $isWindowOpen) {
            return response()->json([
                'status' => false,
                'message' => __('auth.role_selection.timeout'),
                'code' => 'REGISTRATION_WINDOW_EXPIRED',
                'action' => 'show_limited_attachments',
                'meta' => $metaData,
            ], 403);
        }

        return response()->json([
            'status' => false,
            'message' => __('auth.role_selection.required'),
            'code' => 'ROLE_NOT_SELECTED',
            'action' => 'force_role_selection',
            'meta' => $metaData,
        ], 403);
    }
}
