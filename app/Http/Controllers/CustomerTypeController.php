<?php

namespace App\Http\Controllers;

use App\Http\Resources\Profile\BadgeResource;
use App\Http\Resources\Profile\CustomerTypeResource;
use App\Models\Badge;
use App\Models\CustomerType;
use Illuminate\Http\JsonResponse;

class CustomerTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = CustomerType::all();

        return response()->json([
            'status' => 'success',
            'data' => CustomerTypeResource::collection($types),
        ]);
    }

    public function getBadges(): JsonResponse
    {
        $badges = Badge::all();

        return response()->json([
            'status' => 'success',
            'data' => BadgeResource::collection($badges),
        ]);
    }
}
