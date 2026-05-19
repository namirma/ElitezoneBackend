<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Exception;
use Illuminate\Support\Facades\DB;

class WalletTransactionEngine
{
    /**
     * پردازش تراکنش کیف پول با مکانیزم قفل (Pessimistic Locking)
     */
    public function process(
        string $operation,
        float $amount,
        string $walletCode,
        string $status,
        Order $order,
        ?string $description = null,
        ?int $targetUserId = null
    ): void {
        // اجرای کل عملیات در یک تراکنش برای حفظ یکپارچگی داده‌ها
        DB::transaction(function () use ($operation, $amount, $walletCode, $status, $order, $description, $targetUserId) {

            // 1. تعیین کاربر هدف
            $userId = $targetUserId ?? $this->resolveUserId($walletCode, $order);

            $wallet = Wallet::firstOrCreate(['user_id' => $userId]);
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            // 3. تعیین نوع عملیات
            $type = ($operation === '+') ? 'deposit' : 'withdraw';

            $isHold = ($status === FinancialCodes::STATUS_HOLD || $status === 'hold');

            if ($operation === '+') {
                if ($isHold) {
                    $wallet->balance_hold += $amount;
                } else {
                    $wallet->balance += $amount;
                }
            } else {
                if ($isHold) {
                    if ($wallet->balance_hold < $amount) {
                        throw new InsufficientBalanceException($amount, (float) $wallet->balance_hold);
                    }
                    $wallet->balance_hold -= $amount;
                } else {
                    if ($wallet->balance < $amount) {
                        throw new InsufficientBalanceException($amount, (float) $wallet->balance);
                    }
                    $wallet->balance -= $amount;
                }
            }

            $wallet->save();

            // 5. ثبت تراکنش در جدول تاریخچه
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'type' => $type,
                'status' => $status,
                'description' => $description,
                'meta' => json_encode(['code' => $walletCode, 'op' => $operation]),
            ]);
        });
    }

    /**
     * تشخیص آیدی کاربر بر اساس کد کیف پول
     */
    private function resolveUserId(string $code, Order $order): int
    {
        return match ($code) {
            FinancialCodes::WALLET_OWNER => $order->placer_user_id,
            FinancialCodes::WALLET_SUPPLIER => $order->beneficiary_user_id ?? $order->supplier_user_id ?? 1, // فال‌بک ایمن‌تر
            FinancialCodes::WALLET_SAZE => 1, // بهتر است این آیدی از کانفیگ خوانده شود: config('app.admin_id')
            default => throw new Exception("کد کیف پول ناشناخته: $code"),
        };
    }
}
