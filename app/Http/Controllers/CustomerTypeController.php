<?php

namespace App\Http\Controllers;

use App\Http\Resources\Profile\BadgeResource;
use App\Http\Resources\Profile\CustomerTypeResource;
use App\Models\Badge;
use App\Models\CustomerType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CustomerTypeController extends Controller
{
    /** داده‌ها ماهانه تغییر می‌کنند — 1 ساعت TTL کافی است */
    private const CACHE_TTL = 3600;

    public function index(): JsonResponse
    {
        $types = Cache::remember('customer_types', self::CACHE_TTL, fn () => CustomerType::all());

        return response()->json([
            'status' => 'success',
            'data' => CustomerTypeResource::collection($types),
        ]);
    }

    public function getBadges(): JsonResponse
    {
        $badges = Cache::remember('badges', self::CACHE_TTL, fn () => Badge::all());

        return response()->json([
            'status' => 'success',
            'data' => BadgeResource::collection($badges),
        ]);
    }
}
