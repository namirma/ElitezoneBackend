<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayHoldFeeAction
{
    /**
     * مبلغ هزینه تمدید — از config/financial.php خوانده می‌شود.
     * مقدار پیشفرض: 50,000,000 ریال = 5 میلیون تومان
     */
    public static function fee(): float
    {
        return (float) config('financial.hold_fee', 50_000_000);
    }

    /** @deprecated use self::fee() — kept for backward compatibility */
    public const FEE_RIALS = 50_000_000;

    public function __construct(
        protected WalletTransactionEngine $walletEngine
    ) {}

    public function execute(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $lockedOrder) {
                throw new \RuntimeException("Order {$order->id} not found.");
            }

            // Idempotency: if already extended with a live expiry, treat as success.
            if (
                $lockedOrder->status === Order::STATUS_RESERVED_EXTENDED
                && $lockedOrder->{Order::RESERVATION_EXPIRES_AT}
                && $lockedOrder->{Order::RESERVATION_EXPIRES_AT}->isFuture()
            ) {
                Log::info("PayHoldFeeAction: سفارش {$lockedOrder->id} قبلاً تمدید شده، عملیات تکراری نادیده گرفته شد.");

                return;
            }

            if (! \in_array($lockedOrder->status, [Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED])) {
                throw new \RuntimeException('تنها سفارش‌های رزرو شده قابل تمدید هستند.');
            }

            $buyerId = $lockedOrder->placer_user_id;
            $buyerWallet = Wallet::where('user_id', $buyerId)->lockForUpdate()->first();

            if (! $buyerWallet || $buyerWallet->balance < self::fee()) {
                throw new InsufficientBalanceException(self::fee(), (float) ($buyerWallet?->balance ?? 0));
            }

            $this->walletEngine->process(
                '-',
                self::fee(),
                FinancialCodes::WALLET_OWNER,
                FinancialCodes::STATUS_AVAILABLE,
                $lockedOrder,
                "هزینه تمدید رزرو سفارش {$lockedOrder->order_number}",
                $buyerId
            );

            $lockedOrder->update([
                Order::STATUS => Order::STATUS_RESERVED_EXTENDED,
                Order::RESERVATION_EXPIRES_AT => now()->addHours(24),
            ]);

            Log::info("PayHoldFeeAction: سفارش {$lockedOrder->id} با موفقیت تمدید شد.");
        });
    }
}
