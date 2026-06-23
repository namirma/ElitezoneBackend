<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Profile\SyncUserBadgeAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\OrderSurveyResource;
use App\Http\Resources\Profile\BadgeResource;
use App\Models\OrderSurvey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class MyItemsController extends Controller
{
    public function badges(SyncUserBadgeAction $badgeSync): JsonResponse
    {
        $user = Auth::user();

        // Sync the saze90/pioneer badges into the pivot first, so this list is correct on
        // the first call (no lag, no refresh). The saze90 lookup is cached.
        $badgeSync->execute($user);

        $badges = $user->badges()->latest()->get();

        return success(
            BadgeResource::collection($badges),
            'my_items.badges.index.success'
        );
    }

    public function surveys(): JsonResponse
    {
        $surveys = OrderSurvey::query()
            ->where(OrderSurvey::USER_ID, Auth::id())
            ->with('order')
            ->latest()
            ->paginate($this->perPage(10));

        return success(
            OrderSurveyResource::collection($surveys),
            'my_items.surveys.index.success'
        );
    }
}
