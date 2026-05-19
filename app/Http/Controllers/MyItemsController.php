<?php

namespace App\Http\Controllers;

use App\Http\Resources\BadgeResource;
use App\Http\Resources\OrderSurveyResource;
use App\Models\OrderSurvey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class MyItemsController extends Controller
{
    public function badges(): JsonResponse
    {
        $user = Auth::user();
        // Assuming the relation in User model is named 'badges'
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
            ->paginate(10);

        return success(
            OrderSurveyResource::collection($surveys),
            'my_items.surveys.index.success'
        );
    }
}
