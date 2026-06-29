<?php

namespace App\Models\Concerns;

use App\Actions\Order\RefundOrderToWalletAction;
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

    /**
     * عودت وجه به کیف پول کاربر (هنگام لغو سفارش)
     *
     * Delegates to RefundOrderToWalletAction — financial logic belongs
     * in an Action, not in a Model trait.
     */
    public function refundToWallet(): void
    {
        app(RefundOrderToWalletAction::class)->execute($this);
    }
}
