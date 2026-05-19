<?php

namespace App\Observers;

use App\Models\PriceHistory;
use App\Models\ProductPrice;
use Illuminate\Support\Facades\Auth;

class ProductPriceObserver
{
    /**
     * Handle the ProductPrice "created" event.
     */
    public function created(ProductPrice $productPrice): void
    {
        $this->logHistory($productPrice);
    }

    /**
     * Handle the ProductPrice "updated" event.
     */
    public function updated(ProductPrice $productPrice): void
    {
        if ($productPrice->isDirty(['general_price', 'special_price', 'golden_figure'])) {
            $this->logHistory($productPrice);
        }
    }

    /**
     * متد کمکی برای ثبت رکورد در دیتابیس
     */
    protected function logHistory(ProductPrice $productPrice): void
    {
        PriceHistory::create([
            'product_variant_id' => $productPrice->product_variant_id,
            'product_price_id' => $productPrice->id,
            'factory_id' => $productPrice->factory_id,
            'factory_location_id' => $productPrice->factory_location_id,
            'general_price' => $productPrice->general_price,
            'special_price' => $productPrice->special_price,
            'golden_figure' => $productPrice->golden_figure,
            'user_id' => Auth::id(),
        ]);
    }
}
