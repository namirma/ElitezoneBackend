<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Profile\SyncUserBadgeAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BadgeController extends Controller
{
    /**
     * GET /api/profile/badge — resolve and return the authenticated user's badges.
     *
     * Server-side replacement for the old direct frontend call to
     * `https://www.saze90.com/get-certificate?mobile=…` (which broke under CORS).
     * Works for already-registered users too: it reads live on each call and persists
     * the commission tier, so a badge that never loaded before now loads.
     */
    public function show(SyncUserBadgeAction $action): JsonResponse
    {
        $result = $action->execute(Auth::user());

        return success($result, __('profile.badge_loaded'));
    }
}
