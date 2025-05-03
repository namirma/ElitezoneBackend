<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\BadgeResource;
use App\Http\Resources\CustomerTypeResource;
use App\Models\CustomerType;
use App\Models\Badge;
use Illuminate\Http\JsonResponse;

class CustomerTypeController extends Controller
{

    public function index(): JsonResponse
    {
        $types = CustomerType::all();

        return response()->json([
            'status' => 'success',
            'data'   => CustomerTypeResource::collection($types),
        ]);
    }


    public function getBadges(): JsonResponse
    {
        $badges = Badge::all();

        return response()->json([
            'status' => 'success',
            'data'   => BadgeResource::collection($badges),
        ]);
    }
}
