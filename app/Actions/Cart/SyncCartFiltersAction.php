<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use Illuminate\Support\Facades\DB;

class SyncCartFiltersAction
{
    public function execute(Cart $cart, array $filterCategoryIds): Cart
    {
        return DB::transaction(function () use ($cart, $filterCategoryIds) {
            $cart->priceFilterCategories()->sync($filterCategoryIds);

            return $cart->load('items.productVariant');
        });
    }
}
