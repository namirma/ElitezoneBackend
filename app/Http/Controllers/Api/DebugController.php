<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductPriceCache;
use App\Services\Gateways\GatewayFactory;
use Illuminate\Http\JsonResponse;

class DebugController extends Controller
{
    public function testCache()
    {
        return ProductPriceCache::with('variant:id,name')->take(10)->get();
    }

    public function kavirProducts(): JsonResponse
    {
        $gateway = GatewayFactory::make('tesna');

        return response()->json($gateway->getRequest('/api/v1/wh/AllStoreCodes'));
    }
}
