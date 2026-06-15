<?php

namespace App\Http\Controllers;

use App\Http\Resources\Order\ShippingMethodResource;
use App\Models\ShippingMethod;
use Illuminate\Http\JsonResponse;

/**
 * Public lookup for the available shipping methods, so the frontend can present a real
 * choice instead of hardcoding shipping_method_id = 1.
 */
class ShippingMethodController extends Controller
{
    public function index(): JsonResponse
    {
        return success(ShippingMethodResource::collection(ShippingMethod::all()));
    }
}
