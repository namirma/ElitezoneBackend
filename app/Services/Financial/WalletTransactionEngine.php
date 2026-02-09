<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Exception;

class WalletTransactionEngine
{
    /**
     * پردازش تراکنش کیف پول
     * * @param string $operation عملیات: '+' یا '-'
     * @param float $amount مبلغ تراکنش
     * @param string $walletCode کد کیف پول (مثلاً WS, WO)
     * @param string $status وضعیت تراکنش (confirmed, hold)
     * @param Order $order مدل سفارش مرتبط
     * @param string|null $description توضیحات اختیاری
     * @param int|null $targetUserId شناسه کاربر هدف (اختیاری - برای رفع ابهام سوپلایر)
     */
    public function process(
        string $operation,
        float $amount,
        string $walletCode,
        string $status,
        Order $order,
        ?string $description = null,
        ?int $targetUserId = null
    ): void
    {
        // 1. تعیین کاربر هدف
        // اگر آیدی دستی داده شده، از همان استفاده کن، وگرنه طبق روال قبل پیدا کن
        $userId = $targetUserId ?? $this->resolveUserId($walletCode, $order);

        // 2. دریافت یا ساخت کیف پول کاربر
        $wallet = Wallet::firstOrCreate(['user_id' => $userId]);

        // 3. تعیین نوع عملیات برای ثبت در دیتابیس
        $type = ($operation === '+') ? 'deposit' : 'withdraw';

        // 4. اعمال تغییرات ریاضی روی موجودی‌ها
        if ($operation === '+') {
            // در واریز، اگر وضعیت hold باشد، به موجودی بلوکه اضافه می‌شود
            if ($status === FinancialCodes::STATUS_HOLD || $status === 'hold') {
                $wallet->increment('balance_hold', $amount);
            } else {
                $wallet->increment('balance', $amount);
            }
        } else {
            // در برداشت، همیشه از موجودی قابل برداشت کسر می‌شود (حتی اگر منفی شود)
            $wallet->decrement('balance', $amount);
        }

        // 5. ثبت تراکنش در جدول تاریخچه
        WalletTransaction::create([
            'wallet_id'   => $wallet->id,
            'order_id'    => $order->id,
            'amount'      => $amount,
            'type'        => $type,
            'status'      => $status, 
            'description' => $description,
            'meta'        => json_encode(['code' => $walletCode, 'op' => $operation])
        ]);
    }

    /**
     * تشخیص آیدی کاربر بر اساس کد کیف پول (منطق پیش‌فرض)
     */
    private function resolveUserId(string $code, Order $order): int
    {
        return match ($code) {
            FinancialCodes::WALLET_OWNER      => $order->placer_user_id,         // خریدار
            FinancialCodes::WALLET_SUPPLIER   => $order->beneficiary_user_id,    // پیش‌فرض (ممکن است باگ داشته باشد اگر اوردر آیتم‌ها متفاوت باشند)
            FinancialCodes::WALLET_SAZE       => 1,                              // آیدی کیف پول سیستم (ادمین)
            default => throw new Exception("کد کیف پول ناشناخته: $code"),
        };
    }
}
