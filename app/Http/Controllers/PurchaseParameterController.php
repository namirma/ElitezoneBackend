<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\PurchaseParameterGroup;
use App\Models\PurchaseParameterValue;
use Illuminate\Http\Request;

class PurchaseParameterController extends Controller
{

    public function getGroups()
    {
        $groups = PurchaseParameterGroup::active()
            ->ordered()
            ->with(['values' => function ($query) {
                $query->active()->ordered();
            }])
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $groups
        ]);
    }


    public function getUserParameters(Request $request)
    {
        $cart = $this->getCart();
        $cartParameters = $cart->purchaseParameters;

        return response()->json([
            'status' => 'success',
            'data' => $cartParameters
        ]);
    }


    public function saveUserParameters(Request $request)
    {
        $validated = $request->validate([
            'parameters' => ['required', 'array'],
            'parameters.*' => ['integer', 'exists:purchase_parameter_values,id'],
        ]);

        $cart = $this->getCart();
        $cart->purchaseParameters()->sync($validated['parameters']);

        $detection = app(ParameterDetectionController::class)->detectPriceFilters($request);
        $category = $detection->original['data']['price_filter_category'] ?? null;

        if ($category) {
            $cart->priceFilterCategories()->sync([$category['id']]);
        } else {
            $cart->priceFilterCategories()->detach();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'پارامترهای خرید با موفقیت ذخیره شد',
        ]);
    }

    protected function getCart(): Cart
    {
        $sessionId = session()->getId();

        return Cart::firstOrCreate(
            ['session_id' => $sessionId],
            ['user_id' => auth()->id()]
        );
    }
}
