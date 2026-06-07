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

    /** تعداد ساعتی که پرداخت هزینه هولد، اعتبار رزرو را تمدید می‌کند. */
    public static function extensionHours(): int
    {
        return (int) config('financial.hold_fee_extension_hours', 24);
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

            // Double-entry: the hold fee leaves the buyer (WO) and is recognised as
            // platform revenue in the Saze system wallet (WSSG). Crediting WSSG keeps
            // the ledger balanced — never debit a wallet without a matching credit.
            $systemUserId = (int) config('financial.system_user_id', 1);

            $this->walletEngine->process(
                '-',
                self::fee(),
                FinancialCodes::WALLET_OWNER,
                FinancialCodes::STATUS_AVAILABLE,
                $lockedOrder,
                "هزینه تمدید رزرو سفارش {$lockedOrder->order_number}",
                $buyerId
            );

            $this->walletEngine->process(
                '+',
                self::fee(),
                FinancialCodes::WALLET_SAZE,
                FinancialCodes::STATUS_AVAILABLE,
                $lockedOrder,
                "دریافت هزینه تمدید رزرو سفارش {$lockedOrder->order_number}",
                $systemUserId
            );

            $lockedOrder->update([
                Order::STATUS => Order::STATUS_RESERVED_EXTENDED,
                Order::RESERVATION_EXPIRES_AT => now()->addHours(self::extensionHours()),
            ]);

            Log::info("PayHoldFeeAction: سفارش {$lockedOrder->id} با موفقیت تمدید شد.");
        });
    }
}
