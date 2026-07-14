<?php

namespace App\Models\Concerns;

use App\Services\InventoryService;

trait HasOrderActions
{
    /**
     * بازگرداندن موجودی کالا به انبار (هنگام لغو سفارش)
     */
    public function restoreStock(): void
    {
        app(InventoryService::class)->restoreStock($this);
    }
}
